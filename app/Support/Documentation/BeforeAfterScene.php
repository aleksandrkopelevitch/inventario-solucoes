<?php

namespace App\Support\Documentation;

/**
 * An "antes → depois" — the second SCENE type: what changes, aspect by aspect,
 * drawn as two columns (how it was, how it becomes) inside a page.
 *
 * Each row is ONE aspect ("Pedido", "Pagamento"…) with its `before` and its
 * `after`. Either side may be empty, and that is meaningful rather than
 * missing: an empty `before` is something NEW, an empty `after` is something
 * that stops existing. Both empty, or both the same, is not a change and is
 * refused — a comparison row that compares nothing is the shape a model falls
 * into when it pads a list.
 *
 * Like StepScene it carries no geometry: the model chooses the words and
 * docs-scene.js draws them. The column labels default to "Antes"/"Depois" and
 * are worth changing ("Hoje" → "Com a integração", "AS IS" → "TO BE"): they are
 * what tells a reader which world each column is.
 */
final class BeforeAfterScene
{
    public const TYPE = 'before-after';

    public const MIN_CHANGES = 2;

    public const MAX_CHANGES = 6;

    public const MAX_ASPECT = 32;

    public const MAX_SIDE = 120;

    public const MAX_LABEL = 24;

    public const MAX_CAPTION = 120;

    public const DEFAULT_FROM = 'Antes';

    public const DEFAULT_TO = 'Depois';

    /**
     * @param  list<array{aspect: string, before: string, after: string, highlight: bool}>  $changes
     */
    private function __construct(
        public readonly string $caption,
        public readonly string $from,
        public readonly string $to,
        public readonly array $changes,
    ) {}

    /**
     * @param  array<mixed>  $payload
     * @return list<string> empty when the payload is a valid scene
     */
    public static function validate(array $payload): array
    {
        $problems = [];

        foreach (['caption' => self::MAX_CAPTION, 'from' => self::MAX_LABEL, 'to' => self::MAX_LABEL] as $field => $max) {
            $value = $payload[$field] ?? '';

            if (! is_string($value)) {
                $problems[] = "O campo \"{$field}\" deve ser um texto.";
            } elseif (mb_strlen(trim($value)) > $max) {
                $problems[] = "O campo \"{$field}\" passa de {$max} caracteres.";
            }
        }

        $changes = $payload['changes'] ?? null;

        if (! is_array($changes) || ! array_is_list($changes)) {
            return [...$problems, 'O campo "changes" é obrigatório e deve ser uma lista.'];
        }

        if (count($changes) < self::MIN_CHANGES || count($changes) > self::MAX_CHANGES) {
            $problems[] = 'A lista "changes" deve ter entre ' . self::MIN_CHANGES . ' e ' . self::MAX_CHANGES . ' mudanças (tem ' . count($changes) . ').';
        }

        $highlights = 0;

        foreach ($changes as $i => $change) {
            $n = $i + 1;

            if (! is_array($change)) {
                $problems[] = "A mudança {$n} deve ser um objeto.";

                continue;
            }

            $aspect = $change['aspect'] ?? null;

            if (! is_string($aspect) || trim($aspect) === '') {
                $problems[] = "A mudança {$n} precisa de um \"aspect\".";
            } elseif (mb_strlen(trim($aspect)) > self::MAX_ASPECT) {
                $problems[] = "O \"aspect\" da mudança {$n} passa de " . self::MAX_ASPECT . ' caracteres — encurte.';
            }

            $sides = [];
            foreach (['before', 'after'] as $side) {
                $value = $change[$side] ?? '';

                if (! is_string($value)) {
                    $problems[] = "O \"{$side}\" da mudança {$n} deve ser um texto.";

                    continue;
                }

                if (mb_strlen(trim($value)) > self::MAX_SIDE) {
                    $problems[] = "O \"{$side}\" da mudança {$n} passa de " . self::MAX_SIDE . ' caracteres.';
                }

                $sides[$side] = trim($value);
            }

            if (count($sides) === 2) {
                if ($sides['before'] === '' && $sides['after'] === '') {
                    $problems[] = "A mudança {$n} precisa de \"before\" ou de \"after\" (os dois vazios não dizem o que mudou).";
                } elseif (mb_strtolower($sides['before']) === mb_strtolower($sides['after'])) {
                    $problems[] = "Na mudança {$n}, \"before\" e \"after\" são iguais — isso não é uma mudança; remova-a.";
                }
            }

            if (isset($change['highlight']) && ! is_bool($change['highlight'])) {
                $problems[] = "O \"highlight\" da mudança {$n} deve ser true ou false.";
            } elseif (($change['highlight'] ?? false) === true) {
                $highlights++;
            }
        }

        if ($highlights > 1) {
            $problems[] = 'No máximo UMA mudança pode ter "highlight": true (há ' . $highlights . ').';
        }

        return $problems;
    }

    /** @param  array<mixed>  $payload  a payload `validate()` accepted */
    public static function fromArray(array $payload): self
    {
        return new self(
            trim((string) ($payload['caption'] ?? '')),
            trim((string) ($payload['from'] ?? '')) ?: self::DEFAULT_FROM,
            trim((string) ($payload['to'] ?? '')) ?: self::DEFAULT_TO,
            array_map(fn (array $change): array => [
                'aspect'    => trim((string) $change['aspect']),
                'before'    => trim((string) ($change['before'] ?? '')),
                'after'     => trim((string) ($change['after'] ?? '')),
                'highlight' => ($change['highlight'] ?? false) === true,
            ], $payload['changes']),
        );
    }

    /** @return array{type: string, caption: string, from: string, to: string, changes: list<array{aspect: string, before: string, after: string, highlight: bool}>} */
    public function toArray(): array
    {
        return [
            'type'    => self::TYPE,
            'caption' => $this->caption,
            'from'    => $this->from,
            'to'      => $this->to,
            'changes' => $this->changes,
        ];
    }
}
