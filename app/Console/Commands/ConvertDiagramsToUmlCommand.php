<?php

namespace App\Console\Commands;

use App\Contracts\Documentable;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\User;
use App\Services\DocumentationPageService;
use App\Support\Diagrams\DiagramToUml;
use App\Support\Documentation\UmlDiagram;
use App\Support\Documentation\UmlSolutionBindings;
use App\Support\Fold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * `diagrams:to-uml` — the one-shot conversion of the topology drawings into
 * `{% uml %}` blocks, in the documentation each one belongs to (phase 3 of
 * docs-and-specs/solution-relationships-from-uml.md). Deleted in phase 4,
 * together with the `Diagram` model it reads.
 *
 * Where a drawing lands, first match wins:
 *
 * 1. every page that CITES it — the `{% diagram slug="…" %}` line becomes the
 *    block, so the drawing stays exactly where the author put it;
 * 2. the page of its caderno it was drawn FROM — "Desenhar esta página" named
 *    drawings "{título da página} — {modelo}", so the page whose title folds
 *    equal to the name before the last " — " gets it, under `## {nome}`;
 * 3. the caderno's first page, the same way (a caderno with no page gets one,
 *    "Diagramas migrados").
 *
 * Every block goes through the reader's own validator first, and one it would
 * refuse is NOT written: its problems are printed and its citation stays where
 * it was, which phase 4's gate then catches. Saves go through the model, so
 * the relationships index fills as it goes; `--user` signs them.
 *
 * Run `--dry-run` first: it prints where each drawing would go, as what, with
 * how many bindings and relationships, and writes nothing.
 */
class ConvertDiagramsToUmlCommand extends Command
{
    protected $signature = 'diagrams:to-uml
        {--dry-run : Report what would be written, write nothing}
        {--diagram= : Only this diagram (slug)}
        {--user= : Sign the page saves as this account (e-mail)}
        {--keep-picture : Also put the drawing\'s last PNG under the block}
        {--show : Print each converted block}';

    protected $description = 'Convert the topology drawings (/diagrams) into {% uml %} blocks in their documentation';

    public function handle(DiagramToUml $converter, UmlSolutionBindings $bindings, DocumentationPageService $pages): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($email = $this->option('user')) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->error("No account with the e-mail {$email}.");

                return self::FAILURE;
            }

            Auth::setUser($user);
        }

        $diagrams = Diagram::query()
            ->with('notebook')
            ->when($this->option('diagram'), fn ($q, string $slug) => $q->where('slug', $slug))
            ->orderBy('id')
            ->get();

        $written = 0;
        $refused = 0;

        foreach ($diagrams as $diagram) {
            $uml = $converter->convert($diagram);
            $title = str_replace('"', '&quot;', $uml['title']);
            $block = "{% uml type=\"{$uml['type']}\" title=\"{$title}\" %}\n{$uml['body']}\n{% enduml %}";
            $parsed = UmlDiagram::parse($uml['type'], $uml['title'], $uml['body']);

            $this->newLine();
            $this->line("<options=bold>{$diagram->name}</> ({$diagram->slug}) → {$uml['type']}, {$uml['bindings']} binding(s), "
                . count($bindings->links($block)) . ' link(s)');

            if ($this->option('show')) {
                $this->line($block);
            }

            if ($parsed['problems'] !== []) {
                $refused++;
                $this->error('  Not written — the validator refuses it:');
                foreach ($parsed['problems'] as $problem) {
                    $this->line("  - {$problem}");
                }

                continue;
            }

            foreach ($parsed['warnings'] as $warning) {
                $this->warn("  warning: {$warning}");
            }

            [$targets, $rule] = $this->targets($diagram, $pages);

            foreach ($targets as $page) {
                $url = $page->exists ? route('notebooks.pages.edit', [$diagram->notebook, $page]) : '(new page "Diagramas migrados")';
                $this->line("  {$rule}: {$page->title} — {$url}");

                if ($dry) {
                    continue;
                }

                if (! $page->exists) {
                    $page = $pages->create($diagram->notebook, 'Diagramas migrados');
                }

                $figure = $this->option('keep-picture') ? $this->picture($diagram, $page) : '';
                $page->refresh();
                $page->update(['documentation' => $rule === 'cited'
                    ? $this->replaceCitation((string) $page->documentation, $diagram->slug, $block . $figure)
                    : rtrim((string) $page->documentation) . "\n\n## {$diagram->name}\n\n{$block}{$figure}\n"]);
            }

            $written++;
        }

        $this->newLine();
        $this->info(($dry ? 'Dry run — nothing written. ' : '') . "Converted: {$written}. Refused: {$refused}.");

        $left = DocumentationPage::query()->where('documentation', 'like', '%diagram slug=%')->count();
        if (! $dry && $left > 0) {
            $this->warn("{$left} page(s) still cite a diagram — phase 4 will refuse to run until they don't.");
        }

        return self::SUCCESS;
    }

    /**
     * The pages a drawing goes to, and which rule chose them.
     *
     * @return array{0: list<DocumentationPage>, 1: string}
     */
    private function targets(Diagram $diagram, DocumentationPageService $pages): array
    {
        $citing = DocumentationPage::query()
            ->where('documentation', 'like', '%' . $diagram->slug . '%')
            ->get()
            ->filter(fn (DocumentationPage $page) => preg_match($this->citation($diagram->slug), (string) $page->documentation) === 1)
            ->values()
            ->all();

        if ($citing !== []) {
            return [$citing, 'cited'];
        }

        $source = trim((string) preg_replace('/\s+—\s+[^—]+$/u', '', $diagram->name));
        $drawnFrom = $diagram->notebook->pages()->get(['id', 'notebook_id', 'slug', 'title', 'documentation'])
            ->first(fn (DocumentationPage $page) => Fold::text($page->title) === Fold::text($source));

        if ($drawnFrom) {
            return [[$drawnFrom], 'drawn from'];
        }

        return [[$pages->firstPage($diagram->notebook) ?? new DocumentationPage(['title' => 'Diagramas migrados'])], 'first page'];
    }

    private function citation(string $slug): string
    {
        return '/^[ \t]*\{%\s*diagram\s+slug="' . preg_quote($slug, '/') . '"\s*%\}[ \t]*$/m';
    }

    private function replaceCitation(string $body, string $slug, string $block): string
    {
        return (string) preg_replace_callback($this->citation($slug), fn () => $block, $body);
    }

    /** The drawing's last PNG, copied into the page, as the one-line figure the dialect reads. */
    private function picture(Diagram $diagram, DocumentationPage $page): string
    {
        $media = $diagram->picture();

        if ($media === null || ! is_file($media->getPath())) {
            return '';
        }

        $copy = $media->copy($page, Documentable::DOCS_COLLECTION);
        $alt = str_replace('"', '&quot;', $diagram->name);

        return "\n\n<figure><img src=\"/files/{$copy->getKey()}\" alt=\"{$alt}\"><figcaption>Desenho original</figcaption></figure>";
    }
}
