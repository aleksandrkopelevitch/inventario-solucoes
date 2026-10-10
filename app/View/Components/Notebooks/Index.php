<?php

namespace App\View\Components\Notebooks;

use App\Models\Notebook;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\Component;

/**
 * The cadernos catalog, renderable as an updatable slot
 * (`notebooks-index-slot`) — one table row per notebook with the solutions
 * it documents and whether `/docs` shows it.
 *
 * There is no page-count column. It was here, and it answered nothing this
 * screen is for: how much of a caderno is written is the coverage screen's
 * question (`documentation.index`), and the "Com conteúdo"/"Sem conteúdo"
 * filter still narrows by it.
 *
 * The search deliberately spans three things a person might remember about a
 * caderno: its own name, a page title inside it, and the name of a solution it
 * describes. The last one is what makes "where is the Digibee documentation?"
 * answerable without knowing what the caderno holding it was called.
 *
 * It is also where an admin decides what the internal knowledge base (`/docs`)
 * shows: the "Em /docs" column carries the publication switch, and the status
 * filter narrows to what is (or is not) published. That used to be a screen of
 * its own (`/docs/settings`) listing the same cadernos a second time, with the
 * same search, to flip one column — so it was folded in here. Everybody sees
 * the column; only `NotebookPolicy::administerAny` gets the switch.
 */
class Index extends Component
{
    use Renderable;

    public const DOM_ID = 'notebooks-index-slot';

    /**
     * Table columns the catalog is sortable by (`filter[sort]`, e.g.
     * `solutions` or `-solutions` for descending — the header toggle is
     * `x-ui.sortable-th`). Each maps to what it actually orders by: "Soluções"
     * orders by how many a caderno documents, since a list of names has no
     * single value to sort.
     */
    private const SORTS = [
        'name'      => ['name'],
        'solutions' => ['solutions_count'],
    ];

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
        $status = in_array($this->filters['status'] ?? null, ['documented', 'empty', 'shared', 'unlinked', 'published', 'unpublished'], true)
            ? $this->filters['status']
            : null;
        [$sort, $direction] = $this->parseSort($this->filters['sort'] ?? null);
        // Once for the collection, not per row: publishing is the admin's
        // whatever the caderno, so there is no row this could answer
        // differently for.
        $canPublish = auth()->user()?->can('administerAny', Notebook::class) ?? false;

        $notebooks = Notebook::query()
            ->select('id', 'name', 'slug', 'public_token', 'published_at')
            // The page counts are no longer a column: they feed the two
            // confirmations, which say what deleting or publishing costs.
            ->withCount([
                'pages',
                'diagrams',
                'solutions',
                'pages as documented_count' => fn (Builder $q) => $q
                    ->whereNotNull('documentation')->where('documentation', '<>', ''),
            ])
            ->with('solutions:id,name,slug')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereFolded('name', $search)
                ->orWhereHas('pages', fn (Builder $p) => $p->whereFolded('title', $search))
                ->orWhereHas('solutions', fn (Builder $s) => $s->whereFolded('name', $search))))
            ->when($status === 'documented', fn (Builder $q) => $q->whereHas('documentedPages'))
            ->when($status === 'empty', fn (Builder $q) => $q->whereDoesntHave('documentedPages'))
            ->when($status === 'shared', fn (Builder $q) => $q->whereNotNull('public_token'))
            ->when($status === 'unlinked', fn (Builder $q) => $q->whereDoesntHave('solutions'))
            ->when($status === 'published', fn (Builder $q) => $q->published())
            ->when($status === 'unpublished', fn (Builder $q) => $q->whereNull('published_at'))
            ->tap(fn (Builder $q) => collect(self::SORTS[$sort])
                ->each(fn (string $column) => $q->orderBy($column, $direction)))
            ->orderBy('name')
            ->get();

        return view('components.notebooks.index', [
            'domId'     => self::DOM_ID,
            'notebooks' => $notebooks->map(fn (Notebook $notebook) => [
                'name'     => $notebook->name,
                'url'      => route('notebooks.show', $notebook),
                'panelUrl' => route('notebooks.panel.edit', $notebook),
                'isShared' => $notebook->public_token !== null,
                // The knowledge base (`/docs`). The filters ride on the toggle
                // for the same reason they ride on the delete below.
                'published'      => $notebook->isPublished(),
                'publishedAt'    => $notebook->published_at?->format('d/m/Y'),
                'readUrl'        => $notebook->knowledgeBaseUrl(),
                'toggleUrl'      => route('notebooks.publication', ['notebook' => $notebook, 'filter' => $this->filters]),
                'publishConfirm' => $this->publishConfirm($notebook),
                // Per row, and against the real model: `update` on
                // NotebookPolicy takes a Notebook, so a `@can('update',
                // Notebook::class)` in the view is a TypeError, not a denial.
                'canEdit' => auth()->user()?->can('update', $notebook) ?? false,
                // Deleting is the ADMIN's, not the editor's — the same split the
                // rest of the app keeps (`UserRole`), and it matters more here
                // than anywhere: a caderno delete takes its whole page tree with
                // it, so an editor who may rewrite a page must not be able to
                // remove the 133 they did not write.
                'canDelete' => auth()->user()?->can('delete', $notebook) ?? false,
                // The filters ride along so the slot this rebuilds is the list
                // the person was actually looking at — see the "Preserving
                // filters" rule. `route()` drops an empty `filter` array
                // entirely, so this is safe to do unconditionally.
                'deleteUrl' => route('notebooks.destroy', ['notebook' => $notebook, 'filter' => $this->filters]),
                // Built here rather than in the view because it states a
                // CONSEQUENCE, and the two that matter are counted, not
                // guessed: how many pages go with it, and whether a link
                // somebody already holds stops working.
                'deleteConfirm' => $this->confirm($notebook),
                'solutions'     => $notebook->solutions->map(fn (Solution $solution) => [
                    'name' => $solution->name,
                    'url'  => route('solutions.show', $solution),
                ])->all(),
            ]),
            'filters'    => $this->filters,
            'hasFilters' => $search !== '' || $status !== null,
            'canPublish' => $canPublish,
        ]);
    }

    /**
     * Splits `filter[sort]` (e.g. `-pages`) into a whitelisted column key and
     * direction — an unknown, absent or malformed (an array via
     * `filter[sort][]=`) value falls back to `name` asc, the same default the
     * headers render as inactive.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    private function parseSort(mixed $sort): array
    {
        $sort = is_string($sort) && $sort !== '' ? $sort : 'name';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sort = ltrim($sort, '-');

        return array_key_exists($sort, self::SORTS) ? [$sort, $direction] : ['name', 'asc'];
    }

    /**
     * What deleting this caderno actually costs, said before it happens.
     *
     * A `window.confirm` naming only the caderno reads the same whether it
     * holds nothing or holds an imported GitBook space, and the page tree is
     * exactly what makes those two different acts. The drawings count for the
     * same reason: they belong to the caderno and are deleted with it.
     */
    private function confirm(Notebook $notebook): string
    {
        $sentence = 'Excluir o caderno "' . $notebook->name . '"?';

        if ($notebook->pages_count > 0) {
            $sentence .= ' ' . ($notebook->pages_count === 1
                ? 'A página dele vai junto.'
                : 'As ' . $notebook->pages_count . ' páginas dele vão junto.');
        }

        if ($notebook->diagrams_count > 0) {
            $sentence .= ' ' . ($notebook->diagrams_count === 1
                ? 'O diagrama dele também é excluído.'
                : 'Os ' . $notebook->diagrams_count . ' diagramas dele também são excluídos.');
        }

        if ($notebook->public_token !== null) {
            $sentence .= ' O link público para de funcionar.';
        }

        return $sentence . ' Isso não pode ser desfeito.';
    }

    /**
     * What flipping the "Em /docs" switch actually does, said before it happens.
     *
     * Publishing is the one act in this app whose audience is "everybody at
     * Leo Madeiras", and a switch that silently does that is a switch somebody
     * flips while scrolling a table. Unpublishing gets one too, for the
     * opposite reason: the caderno disappears from a place people have started
     * linking to. The empty case is named because publishing a caderno with
     * nothing written in it is allowed and is almost always a mistake.
     */
    private function publishConfirm(Notebook $notebook): string
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
