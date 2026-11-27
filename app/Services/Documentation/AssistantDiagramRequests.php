<?php

namespace App\Services\Documentation;

use App\Exceptions\UmlDraftFailed;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\UmlDiagram;
use Throwable;

/**
 * Lets the Documentation Assistant put UML diagrams in its draft WITHOUT
 * writing one.
 *
 * The assistant is never taught the `{% uml %}` JSON: ~8 KB more syntax in
 * every turn, and a reply that rewrites the whole page is the worst place for
 * a notation that is validated as a whole. It writes a REQUEST instead, on
 * lines of its own:
 *
 *     {% diagrama tipo="sequence" titulo="Criação do pedido" %}
 *     quem participa, em que ordem, o que passa entre eles
 *     {% enddiagrama %}
 *
 * or, to change a diagram already on the page, the request takes the place of
 * that diagram's `[[BLOCK-n]]` marker (BlockVault):
 *
 *     {% diagrama altera="[[BLOCK-3]]" %}acrescente o retry no Digibee{% enddiagrama %}
 *
 * Each request is handed to UmlDraftService — the "Gerar com IA" of the block,
 * with its validator, its one repair round and its catalog of slugs — grounded
 * on the DRAFT the assistant just wrote, and replaced by the `{% uml %}` block
 * it answers. Spec: docs-and-specs/done/uml-ai-generation.md §6, which asked
 * for exactly this ("it should call UmlDraftService rather than learn the
 * syntax itself").
 *
 * It runs on the RAW draft, before BlockVault restores anything: the marker in
 * `altera` is what says which diagram is meant, and the audit has already
 * counted it as kept (it is in the draft), which is right — the diagram is
 * replaced, not lost. A request that cannot be honoured never costs the page
 * its diagram: a failed change keeps the original marker, a failed new one is
 * left out, and both are said out loud in the reply (`notices`).
 */
final class AssistantDiagramRequests
{
    /**
     * Requests honoured per turn. Each is one or two model calls of up to
     * `uml_timeout` inside the reply job, after the reply itself
     * (GenerateDocumentationChatReply::TIMEOUT is sized for it).
     */
    public const MAX_PER_TURN = 2;

    /**
     * Starting a line and ending one; the body — the description or the
     * change asked for — may sit on the same line or on lines of its own.
     */
    private const PATTERN = '/^[ \t]*\{%\s*diagrama\b(?<attrs>[^%]*)%\}(?<body>.*?)\{%\s*enddiagrama\s*%\}[ \t]*$/ms';

    public function __construct(private readonly UmlDraftService $drafts) {}

    /**
     * @param  float  $deadline  unix time past which no request is started
     * @return array{draft: string, notices: list<string>, stats: array{requested: int, generated: int, failed: int}}
     */
    public function resolve(string $draft, BlockVault $blocks, float $deadline): array
    {
        if (preg_match_all(self::PATTERN, $draft, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return ['draft' => $draft, 'notices' => [], 'stats' => ['requested' => 0, 'generated' => 0, 'failed' => 0]];
        }

        // What every diagram is drawn from: the page as the assistant just
        // wrote it, with the requests themselves and the frozen blocks as words.
        $grounding = (string) preg_replace(self::PATTERN, '[diagrama]', $draft);

        $notices = [];
        $replacements = [];
        $generated = 0;

        foreach ($matches as $i => $match) {
            [$whole, $offset] = $match[0];
            $attrs = self::attributes($match['attrs'][0]);
            $instruction = trim($match['body'][0] ?? '');
            $request = $this->request($attrs, $blocks);
            $name = $request['name'];

            // Whatever goes wrong, a change keeps the diagram it was about.
            $fallback = $request['marker'] ?? '';

            $refusal = match (true) {
                $request['refusal'] !== null                                                              => $request['refusal'],
                $i >= self::MAX_PER_TURN                                                                  => 'passou do limite de ' . self::MAX_PER_TURN . ' diagramas por resposta. Peça de novo na próxima mensagem.',
                self::insideContainer($draft, $offset)                                                    => 'estava dentro de um destaque ou de abas, onde o editor não mostra diagramas. Peça de novo fora deles.',
                microtime(true) + 1.5 * (int) config('services.documentation_ai.uml_timeout') > $deadline => 'não houve tempo para montá-lo nesta resposta. Peça de novo.',
                default                                                                                   => null,
            };

            if ($refusal !== null) {
                $notices[] = "⚠️ Diagrama {$name}: {$refusal}";
                $replacements[] = [$offset, strlen($whole), $fallback];

                continue;
            }

            try {
                $result = $this->drafts->draft($request['type'], $request['title'], $grounding, $instruction !== '' ? $instruction : null, $request['current']);
            } catch (UmlDraftFailed $e) {
                $notices[] = "⚠️ Diagrama {$name}: {$e->getMessage()}" . ($fallback !== '' ? ' O diagrama anterior foi mantido.' : '');
                $replacements[] = [$offset, strlen($whole), $fallback];

                continue;
            } catch (Throwable $e) {
                // A provider error must not cost the person the whole reply.
                report($e);
                $notices[] = "⚠️ Diagrama {$name}: não foi possível montá-lo agora." . ($fallback !== '' ? ' O diagrama anterior foi mantido.' : '');
                $replacements[] = [$offset, strlen($whole), $fallback];

                continue;
            }

            $generated++;
            $left = [...$result['problems'], ...$result['warnings']];
            if ($left !== []) {
                $notices[] = "⚠️ O diagrama {$name} veio com pontos a corrigir — depois de aplicar, abra o código do bloco: "
                    . implode(' ', array_slice($left, 0, 3)) . (count($left) > 3 ? ' (e mais ' . (count($left) - 3) . ')' : '');
            }

            $replacements[] = [$offset, strlen($whole), self::umlBlock($request['type'], $request['title'], $result['source'])];
        }

        // From the end, so every earlier offset still holds.
        foreach (array_reverse($replacements) as [$offset, $length, $replacement]) {
            $draft = substr_replace($draft, $replacement, $offset, $length);
        }

        return [
            'draft'   => (string) preg_replace("/\n{3,}/", "\n\n", $draft),
            'notices' => $notices,
            'stats'   => ['requested' => count($matches), 'generated' => $generated, 'failed' => count($matches) - $generated],
        ];
    }

    /**
     * What a request asks for: a new diagram (`tipo`, `titulo`) or a change
     * to a frozen one (`altera`, whose type and title it keeps unless
     * `titulo` renames it).
     *
     * @param  array<string, string>  $attrs
     * @return array{type: string, title: string, current: ?string, marker: ?string, name: string, refusal: ?string}
     */
    private function request(array $attrs, BlockVault $blocks): array
    {
        $title = trim($attrs['titulo'] ?? '');

        if (isset($attrs['altera'])) {
            $marker = trim($attrs['altera']);
            $block = $blocks->block($marker);
            $original = $block !== null ? self::parseUml($block) : null;

            if ($original === null) {
                return [
                    'type' => 'sequence', 'title' => $title, 'current' => null,
                    // A marker for something that is not a diagram is kept as
                    // it was; one this turn never issued is not a block at all.
                    'marker'  => $block !== null ? $marker : null,
                    'name'    => '"' . $marker . '"',
                    'refusal' => $block !== null
                        ? 'só diagramas UML podem ser alterados assim; o bloco foi mantido.'
                        : 'o marcador não corresponde a nenhum diagrama da página.',
                ];
            }

            $title = $title !== '' ? $title : $original['title'];

            return [
                'type'    => $original['type'],
                'title'   => $title,
                'current' => $original['source'],
                'marker'  => $marker,
                'name'    => $title !== '' ? "\"{$title}\"" : mb_strtolower(UmlDiagram::TYPES[$original['type']]),
                'refusal' => null,
            ];
        }

        $type = trim($attrs['tipo'] ?? '');
        $name = $title !== '' ? "\"{$title}\"" : 'novo';

        return [
            'type'    => $type,
            'title'   => $title,
            'current' => null,
            'marker'  => null,
            'name'    => $name,
            'refusal' => isset(UmlDiagram::TYPES[$type])
                ? null
                : 'o tipo "' . $type . '" não existe (use ' . implode(', ', array_keys(UmlDiagram::TYPES)) . ').',
        ];
    }

    /** @return array<string, string> */
    private static function attributes(string $text): array
    {
        preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $text, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn (array $pair) => [strtolower($pair[1]) => html_entity_decode($pair[2], ENT_QUOTES)])->all();
    }

    /** @return array{type: string, title: string, source: string}|null */
    private static function parseUml(string $block): ?array
    {
        if (preg_match('/^\{%\s*uml\b(?<attrs>.*?)%\}\s*\n(?<source>.*)\n\s*\{%\s*enduml\s*%\}$/s', trim($block), $m) !== 1) {
            return null;
        }

        $attrs = self::attributes($m['attrs']);
        $type = $attrs['type'] ?? '';

        return isset(UmlDiagram::TYPES[$type])
            ? ['type' => $type, 'title' => trim($attrs['title'] ?? ''), 'source' => trim($m['source'])]
            : null;
    }

    /**
     * The block exactly as the editor writes it (docs-markdown.js
     * `serializeUml()`): `"` in an attribute as `&quot;`, no `title` when
     * there is none — so opening the page and saving it changes nothing.
     */
    private static function umlBlock(string $type, string $title, string $source): string
    {
        $title = $title !== '' ? ' title="' . str_replace('"', '&quot;', $title) . '"' : '';

        return "{% uml type=\"{$type}\"{$title} %}\n" . trim($source) . "\n{% enduml %}";
    }

    /**
     * Whether $offset sits inside a `{% hint %}` or `{% tabs %}`: the editor
     * shows a hint's body as inline text (the raw JSON, to the author) and
     * MCP writes refuse a diagram there (doc-uml.md), so it is not drawn into one.
     */
    private static function insideContainer(string $draft, int $offset): bool
    {
        $before = substr($draft, 0, $offset);

        foreach (['hint', 'tabs'] as $tag) {
            $open = preg_match_all('/\{%\s*' . $tag . '\b/', $before);
            $close = preg_match_all('/\{%\s*end' . $tag . '\s*%\}/', $before);

            if ($open > $close) {
                return true;
            }
        }

        return false;
    }
}
