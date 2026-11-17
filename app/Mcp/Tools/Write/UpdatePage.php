<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Documentation\SavePageContent;
use App\Exceptions\StalePageVersion;
use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\RecordFields;
use App\Mcp\Support\ShrinkGuard;
use App\Mcp\ToolResult;
use App\Models\DocumentationPage;
use App\Support\Documentation\SecretText;
use Illuminate\Support\Facades\Validator;

/**
 * Rewrites a page's title and/or its whole Markdown body.
 *
 * The body arrives WHOLE — the model read it with `get_documentation_page`,
 * changed it, and sends all of it back — so this tool carries the two
 * protections a whole-body write needs (docs-and-specs/mcp-write-tools.md §6.3):
 *
 * - **`version`, required.** The page must still be the one the model read.
 *   Otherwise somebody saved in between — in the app, typing — and this write
 *   would erase it. Checked again under a row lock by `SavePageContent`.
 * - **Guards against LOSING content**, each refusing with the exact list of
 *   what would be lost: a protected value whose marker is missing (deleting
 *   the marker deletes the value, by design, in `SecretText::restore()`), a
 *   diagram citation or file reference that is gone, or a body that shrank
 *   sharply — the shape of a model writing "[restante inalterado]". The
 *   confirmation must REPEAT the list rather than be a boolean, so the call
 *   the client shows the person before approving spells out the loss.
 */
class UpdatePage extends DocumentationWriteTool
{
    public function name(): string
    {
        return 'update_page';
    }

    public function title(): string
    {
        return 'Editar uma página';
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function description(): string
    {
        return <<<'TXT'
        Altera o título e/ou o texto de uma página, em nome da pessoa conectada. O texto
        vai INTEIRO: leia com get_documentation_page, altere e envie tudo de volta com o
        "version" que veio na leitura. Mantenha cada [[SECRET-…]] como está.

        Se a página mudou desde a leitura, a chamada é recusada: leia de novo. Se o texto
        novo perder um valor protegido, um diagrama, um arquivo ou boa parte da página, a
        chamada é recusada listando o que se perderia: mostre a lista à pessoa e só repita
        com a confirmação dela, copiando os itens em remove_secrets/confirm_removals.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'notebook'         => ['type' => 'string', 'description' => 'Slug do caderno.'],
                'page'             => ['type' => 'string', 'description' => 'Slug da página.'],
                'version'          => ['type' => 'string', 'description' => 'O "version" devolvido por get_documentation_page.'],
                'title'            => ['type' => 'string', 'description' => 'Novo título (o slug não muda).'],
                'content'          => ['type' => 'string', 'description' => 'O texto COMPLETO da página, em Markdown.'],
                'remove_secrets'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Só com confirmação da pessoa: os marcadores [[SECRET-…]] que devem ser apagados.'],
                'confirm_removals' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Só com confirmação da pessoa: os itens que a recusa listou (ex.: "diagram:fluxo", "file:812", "shrink:30%").'],
            ],
            'required' => ['notebook', 'page', 'version'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $args = new Arguments($arguments);
        $notebook = $this->notebook($args->required('notebook'), $actor);

        if ($notebook instanceof ToolResult) {
            return $notebook;
        }

        $page = $this->page($notebook, $args->required('page'));

        if ($page instanceof ToolResult) {
            return $page;
        }

        if (! $actor->user?->can('update', $page)) {
            return $this->forbidden('editar páginas deste caderno');
        }

        $version = $args->required('version');
        $title = $args->string('title');
        $content = is_string($arguments['content'] ?? null) ? $arguments['content'] : null;

        if ($title === null && $content === null) {
            return ToolResult::failure('Nada para alterar: envie "title" e/ou "content".');
        }

        $validator = Validator::make(
            ['title' => $title, 'content' => $content],
            ['title' => ['nullable', 'string', 'max:255'], 'content' => ['nullable', 'string', 'max:500000']],
        );

        if ($validator->fails()) {
            return $this->refused($validator->errors()->all());
        }

        if ($version !== $page->version()) {
            return self::stale();
        }

        if ($content !== null && ($losses = $this->losses($page, $content, $arguments)) !== null) {
            return $losses;
        }

        $fields = array_keys(array_filter(['title' => $title, 'documentation' => $content], fn ($value) => $value !== null));
        $before = RecordFields::snapshot($page, $fields);

        if ($content !== null) {
            try {
                app(SavePageContent::class)->handle($page, $content, $version);
            } catch (StalePageVersion) {
                return self::stale();
            }
        }

        if ($title !== null) {
            $this->pages->rename($page, $title);
        }

        $changed = $this->log($actor, $page, $before, RecordFields::snapshot($page->refresh(), $fields));

        return $this->pageAnswer($page, $notebook, $changed ? 'Página atualizada.' : 'Nada mudou: o texto e o título enviados já eram os atuais.');
    }

    /**
     * What the new body would lose that the call did not confirm, as the
     * refusal to answer with — or null when nothing unconfirmed is lost.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function losses(DocumentationPage $page, string $content, array $arguments): ?ToolResult
    {
        $stored = (string) $page->documentation;
        $restored = SecretText::restore($content, $stored);
        $removeSecrets = self::strings($arguments['remove_secrets'] ?? []);
        $confirmed = self::strings($arguments['confirm_removals'] ?? []);

        $lost = [];

        // Protected values: a stored id whose marker is not in the new body.
        // A NUMBERED legacy marker (`[[SECRET-2]]`, an editor tab or a draft
        // from before ids) still resolves by ordinal in `restore()`, so it
        // keeps the value at that position.
        $storedIds = self::markers(SecretText::mask($stored));
        $kept = collect(self::markers($content))
            ->map(fn (string $marker) => ctype_digit($marker) ? ($storedIds[(int) $marker - 1] ?? $marker) : $marker)
            ->all();
        $missingSecrets = array_values(array_diff($storedIds, $kept));
        $unconfirmedSecrets = array_values(array_filter(
            $missingSecrets,
            fn (string $id) => ! in_array("[[SECRET-{$id}]]", $removeSecrets, true) && ! in_array($id, $removeSecrets, true),
        ));

        // Citations and files: present before, gone after.
        $references = array_values(array_diff(self::references($stored), self::references($restored)));
        $unconfirmedReferences = array_values(array_diff($references, $confirmed));

        if ($unconfirmedSecrets !== []) {
            $lost[] = 'valores protegidos — para apagá-los, repita com remove_secrets: '
                . json_encode(array_map(fn (string $id) => "[[SECRET-{$id}]]", $unconfirmedSecrets), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($unconfirmedReferences !== []) {
            $lost[] = 'diagramas/arquivos citados — para removê-los, repita com confirm_removals contendo: '
                . json_encode($unconfirmedReferences, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // A sharp shrink, as a BAND of the share kept: the model rewrites the
        // body on the second call and a few characters move, so an exact
        // length would turn the confirmation into a loop. Only the actual band
        // or the one just below it passes — never "shrink:0%" sent up front,
        // which would pre-confirm every loss (phase-2 review).
        $old = mb_strlen($stored);
        $new = mb_strlen($restored);

        if (($band = ShrinkGuard::band($old, $new)) !== null) {
            $accepted = collect($confirmed)
                ->map(fn (string $item) => preg_match('/^shrink:(\d{1,3})%$/', $item, $m) ? (int) $m[1] : null)
                ->filter(fn (?int $confirmedBand) => $confirmedBand !== null && $confirmedBand <= $band && $confirmedBand >= $band - 10)
                ->isNotEmpty();

            if (! $accepted) {
                $lost[] = "boa parte do texto: a página passaria de {$old} para {$new} caracteres. "
                    . 'Se o texto foi abreviado de propósito (e não substituído por algo como "[restante inalterado]"), '
                    . "repita com confirm_removals contendo \"shrink:{$band}%\"";
            }
        }

        return $lost === []
            ? null
            : ToolResult::failure("Nada foi salvo: o texto novo apagaria\n- " . implode("\n- ", $lost)
                . "\nMostre isso à pessoa e só repita com a confirmação dela.");
    }

    private static function stale(): ToolResult
    {
        return ToolResult::failure(
            'A página mudou desde a sua leitura (alguém editou no app?). Nada foi salvo. '
            . 'Leia de novo com get_documentation_page e reaplique a alteração sobre o texto atual.',
        );
    }

    /** @return list<string> the ids of the `[[SECRET-…]]` markers in `$text` */
    private static function markers(string $text): array
    {
        preg_match_all('/\[\[SECRET-([a-z0-9]+)\]\]/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * `diagram:{slug}` and `file:{…}` items, as a refusal names them.
     *
     * A diagram counts only as the renderer reads it — alone on its line
     * (GitbookRenderer): moved into a sentence it is text, and the citation is
     * gone. A file is any `/files/{id}` (an image, a link, a `{% file %}`), or
     * a `{% file src="…" %}` pointing anywhere else.
     *
     * @return list<string>
     */
    private static function references(string $text): array
    {
        preg_match_all('/^\s*\{%\s*diagram\s+slug="([^"]+)"\s*%\}\s*$/m', $text, $diagrams);
        preg_match_all('#/files/(\d+)#', $text, $files);
        preg_match_all('/\{%\s*file\s+src="([^"]+)"/', $text, $sources);

        return array_values(array_unique([
            ...array_map(fn (string $slug) => "diagram:{$slug}", $diagrams[1]),
            ...array_map(fn (string $id) => "file:{$id}", $files[1]),
            ...array_map(fn (string $src) => "file:{$src}", array_filter($sources[1], fn (string $src) => ! preg_match('#^/files/\d+$#', $src))),
        ]));
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn ($item) => is_scalar($item) ? trim((string) $item) : '',
            is_array($value) ? $value : [$value],
        ), fn (string $item) => $item !== ''));
    }
}
