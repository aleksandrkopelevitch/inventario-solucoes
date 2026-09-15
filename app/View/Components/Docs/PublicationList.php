<?php

namespace App\View\Components\Docs;

use App\Models\Notebook;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\Component;

/**
 * The knowledge-base settings list: every caderno in the app, with the switch
 * that decides whether `/docs` shows it.
 *
 * EVERY caderno, not only the published ones — the screen's job is the
 * decision, and a list of what is already published cannot be used to publish
 * anything else. The published ones are pinned to the top so the answer to
 * "what is on /docs today" is readable without scrolling past two hundred rows
 * that are not.
 *
 * Filters follow the catalog's own vocabulary (`Notebooks\Index`) so the two
 * screens narrow the same way: `whereFolded` over the caderno's name, a page
 * title inside it, and the name of a solution it documents — the last of which
 * is what makes "where is the Digibee documentation" answerable by somebody who
 * does not know what the caderno was called.
 */
class PublicationList extends Component
{
    use Renderable;

    public const DOM_ID = 'docs-publication-list-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $search = trim((string) ($this->filters['search'] ?? ''));
        $status = in_array($this->filters['status'] ?? null, ['published', 'unpublished'], true)
            ? $this->filters['status']
            : null;

        $notebooks = Notebook::query()
            ->select('id', 'name', 'slug', 'published_at', 'public_token')
            ->withCount([
                'pages as documented_count' => fn (Builder $q) => $q
                    ->whereNotNull('documentation')->where('documentation', '<>', ''),
            ])
            ->with('solutions:id,name')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereFolded('name', $search)
                ->orWhereHas('pages', fn (Builder $p) => $p->whereFolded('title', $search))
                ->orWhereHas('solutions', fn (Builder $s) => $s->whereFolded('name', $search))))
            ->when($status === 'published', fn (Builder $q) => $q->published())
            ->when($status === 'unpublished', fn (Builder $q) => $q->whereNull('published_at'))
            // Published first, then by name. Postgres sorts NULLs last on an
            // ASC order by default and SQLite sorts them FIRST, so the order is
            // expressed as a boolean the two drivers agree on rather than as
            // `orderBy('published_at')` — the suite runs SQLite and the app
            // runs Postgres (§ Searching, on bugs a driver difference hides).
            ->orderByRaw('case when published_at is null then 1 else 0 end')
            ->orderBy('name')
            ->get();

        return view('components.docs.publication-list', [
            'domId'     => self::DOM_ID,
            'total'     => $notebooks->count(),
            'published' => $notebooks->filter->isPublished()->count(),
            'notebooks' => $notebooks->map(fn (Notebook $notebook) => [
                'name'        => $notebook->name,
                'published'   => $notebook->isPublished(),
                'publishedAt' => $notebook->published_at?->format('d/m/Y'),
                'documented'  => $notebook->documented_count,
                // A caderno with no written page is publishable and says so:
                // refusing it would be a rule nobody asked for (a tree of
                // section pages being filled in this week is a normal state),
                // but publishing an empty one is almost always a mistake.
                'isEmpty'   => $notebook->documented_count === 0,
                'hasLink'   => $notebook->public_token !== null,
                'readUrl'   => $notebook->knowledgeBaseUrl(),
                'editUrl'   => route('notebooks.show', $notebook),
                'toggleUrl' => route('notebooks.publication', ['notebook' => $notebook, 'filter' => $this->filters]),
                'confirm'   => $this->confirm($notebook),
                'solutions' => $notebook->solutions->map(fn (Solution $solution) => $solution->name)->all(),
            ]),
            'hasFilters' => $search !== '' || $status !== null,
        ]);
    }

    /**
     * What flipping this switch actually does, said before it happens.
     *
     * Publishing is the one act in this app whose audience is "everybody at
     * Leo Madeiras", and a switch that silently does that is a switch somebody
     * flips while scrolling. Unpublishing gets one too, for the opposite
     * reason: the caderno disappears from a place people have started linking
     * to, and that is not obvious from a control that reads as an on/off.
     *
     * Built here rather than in the view because it states a CONSEQUENCE, the
     * same way `Notebooks\Index::confirm()` does — and because the empty case
     * is worth naming: publishing a caderno with nothing written in it is
     * allowed and is almost always a mistake.
     */
    private function confirm(Notebook $notebook): string
    {
        if ($notebook->isPublished()) {
            return sprintf(
                'Tirar "%s" da base de conhecimento? Ele deixa de aparecer em /docs para todo mundo da Leo.',
                $notebook->name,
            );
        }

        $sentence = sprintf(
            'Publicar "%s"? Qualquer pessoa logada com a conta Leo Madeiras vai poder ler esse caderno em /docs.',
            $notebook->name,
        );

        if ($notebook->documented_count === 0) {
            $sentence .= ' Atenção: ele ainda não tem nenhuma página escrita.';
        }

        return $sentence;
    }
}
