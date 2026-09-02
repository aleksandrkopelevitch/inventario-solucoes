<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsPagesNav;
use App\Http\Requests\SaveNotebookRequest;
use App\Http\Requests\SyncNotebookSolutionsRequest;
use App\Models\Notebook;
use App\Models\Solution;
use App\Services\DocumentationPageService;
use App\View\Components\Notebooks\DocumentedSystems;
use App\View\Components\Notebooks\Index;
use App\View\Components\Notebooks\LinkedSolutions;
use App\View\Components\Notebooks\SharePanel;
use App\View\Components\Solutions\Notebooks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The Caderno itself — its catalog, its name, the solutions it documents and
 * its public link. The tree of pages inside it belongs to
 * `NotebookPageController`.
 *
 * This is the controller that replaced two: `DocumentationGroupController` and
 * the share/CRUD half of `SolutionDocumentationController`. A caderno is
 * addressed by itself (`/notebooks/{notebook}`), the way a diagram is — it is
 * not reached through a solution, because it may describe several, or none.
 */
class NotebookController extends Controller
{
    use BuildsPagesNav;

    /**
     * `panel` is refused for the same reason a page's slug refuses the segments
     * under `notebooks/{notebook}/`: `notebooks/panel` is a real route (the
     * create side panel), registered first, so a caderno slugged that way would
     * be permanently unreachable at its own URL.
     */
    private const RESERVED_SLUGS = ['panel'];

    /** Characters in a freshly generated public link token — see share(). */
    public const TOKEN_LENGTH = 12;

    public function __construct(private readonly DocumentationPageService $pages) {}

    /** Catalog of cadernos. Same action serves HTML and the filtered JSON slot. */
    public function index(Request $request): View|JsonResponse
    {
        $this->authorize('viewAny', Notebook::class);

        $filters = (array) $request->query('filter', []);

        if ($request->wantsJson()) {
            return response()->json([
                'updatableSlots' => [Index::slot($filters)],
            ]);
        }

        return view('notebooks.index', ['filters' => $filters]);
    }

    public function store(SaveNotebookRequest $request): JsonResponse
    {
        $name = $request->validated()['name'];

        $notebook = Notebook::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
        ]);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Caderno criado.',
            'redirect' => route('notebooks.show', $notebook),
        ]);
    }

    /**
     * Opens the caderno on its first page, creating one if it is empty.
     *
     * Creating that first page is a WRITE, gated on `NotebookPolicy::update`
     * (admin or editor): the catalog links here for empty cadernos too, so a viewer
     * following that link used to silently create a page just by browsing.
     * They go back to the catalog instead, which lists this caderno with its
     * (zero) page count.
     */
    public function show(Notebook $notebook): RedirectResponse
    {
        $page = $this->pages->firstPage($notebook);

        if (! $page) {
            if (auth()->user()->cannot('update', $notebook)) {
                return redirect()->route('notebooks.index');
            }

            $page = $this->pages->create($notebook, 'Página inicial');
        }

        return redirect()->route('notebooks.pages.edit', [$notebook, $page]);
    }

    /** Panel to create a caderno, or to edit an existing one (name + linked solutions). */
    public function panel(Request $request, ?Notebook $notebook = null): JsonResponse
    {
        $this->authorize($notebook ? 'update' : 'create', $notebook ?? Notebook::class);

        return response()->json([
            'content' => view('notebooks.panels.form', [
                'notebook' => $notebook,
                'action'   => $notebook
                    ? route('notebooks.update', ['notebook' => $notebook, 'filter' => (array) $request->query('filter', [])])
                    : route('notebooks.store', ['filter' => (array) $request->query('filter', [])]),
                'solutions' => Solution::orderBy('name')->get(['id', 'name']),
                'linked'    => $notebook?->solutions->modelKeys() ?? [],
            ])->render(),
        ]);
    }

    /**
     * Renaming answers with whichever surface asked.
     *
     * Two of them can: the catalog's side panel, and the pages rail's own
     * header (a caderno is renamed where it is read). `?page=` is how the rail
     * says which page it is showing — without it there is no way to rebuild the
     * rail around the right active row, and the header would keep the old name
     * until a reload. Same carry-it-in-the-URL trick the catalog's filters use
     * to survive a side-panel save.
     */
    public function update(SaveNotebookRequest $request, Notebook $notebook): JsonResponse
    {
        $notebook->update(['name' => $request->validated()['name']]);
        $notebook = $notebook->fresh();

        $page = $request->query('page')
            ? $notebook->pages()->where('slug', $request->query('page'))->first()
            : null;

        return response()->json([
            'type'           => 'success',
            'message'        => 'Caderno renomeado.',
            'updatableSlots' => array_values(array_filter([
                Index::slot((array) $request->query('filter', [])),
                $page ? $this->pagesNavSlot($notebook, $page) : null,
            ])),
        ]);
    }

    /**
     * Sets which solutions this caderno documents, wholesale.
     *
     * The response has to carry more than this notebook's own slots: linking is
     * the one mutation whose effect is visible somewhere the user isn't — the
     * "Cadernos" card on each solution's detail page. `ajax-slot.js` no-ops on
     * an id that isn't on the current page, so sending it always is safe and
     * forgetting it leaves that card stale (§ Multiple different slots).
     */
    public function syncSolutions(SyncNotebookSolutionsRequest $request, Notebook $notebook): JsonResponse
    {
        // BEFORE and after, unioned: a solution that was just UNLINKED needs its
        // card refreshed as much as one that was linked — it has to stop showing
        // this caderno. Reading only the new set would leave the removed one
        // stale until its next full page load.
        $affected = $notebook->solutions()->pluck('solutions.id');

        $notebook->solutions()->sync($request->solutionIds());

        $notebook = $notebook->fresh();
        $affected = $affected->merge($notebook->solutions->modelKeys())->unique();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Soluções vinculadas.',
            'updatableSlots' => [
                // Both, because the link is stated in two places on the reader:
                // the popover's own chips (LinkedSolutions, also the caderno
                // panel's) and the right rail's "Esse caderno contempla…"
                // sentence. The second WRAPS the first there, so swapping both
                // just rewrites the same subtree twice — cheaper than making
                // this endpoint guess which screen asked.
                LinkedSolutions::slot($notebook),
                DocumentedSystems::slot($notebook),
                ...Solution::whereKey($affected)->get()->map(fn (Solution $solution) => Notebooks::slot($solution)),
            ],
        ]);
    }

    /**
     * Deleting a caderno — and its whole page tree with it (Notebook::booted()
     * walks the pages through their own models so Spatie cleans each one's
     * embedded media).
     *
     * Answers with the catalog SLOT rather than the `redirect` it used to send.
     * The endpoint had no caller at all until the trash icon on the catalog
     * card, and from there a redirect to the page you are already standing on
     * is a full reload that throws away the filters the URL still shows. The
     * filters come back in on the query string for the same reason every other
     * mutation in this app carries them.
     */
    public function destroy(Request $request, Notebook $notebook): JsonResponse
    {
        $this->authorize('delete', $notebook);

        $name = $notebook->name;
        $notebook->delete();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Caderno "' . $name . '" excluído.',
            'updatableSlots' => [Index::slot((array) $request->query('filter', []))],
        ]);
    }

    /**
     * Generates (if it doesn't exist yet) the public link token and returns the panel.
     *
     * `TOKEN_LENGTH` is load-bearing, not cosmetic: the token IS the entire
     * authorization on `public-docs/{token}` — those routes carry no auth
     * middleware and no `throttle`, so guessing one is the whole attack. 12
     * alphanumeric characters is ~71 bits, which needs neither a rate limit nor
     * a retry loop for the unique index. Anything short enough to read out loud
     * (a GitBook-style 4 characters is ~24 bits, findable in minutes) needs both
     * and is still guessable — shorten this only together with them.
     */
    public function share(Notebook $notebook): JsonResponse
    {
        // `administer`, not `update`: an editor writes the pages, an admin
        // decides whether the caderno is published (see NotebookPolicy).
        $this->authorize('administer', $notebook);

        if (! $notebook->public_token) {
            $notebook->update(['public_token' => Str::random(self::TOKEN_LENGTH)]);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => 'Link público gerado.',
            'updatableSlots' => [SharePanel::slot($notebook->fresh())],
        ]);
    }

    /** Revokes the public link (clears the token — the old link stops working). */
    public function unshare(Notebook $notebook): JsonResponse
    {
        $this->authorize('administer', $notebook);

        $notebook->update(['public_token' => null]);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Acesso público revogado.',
            'updatableSlots' => [SharePanel::slot($notebook->fresh())],
        ]);
    }

    /**
     * Generates a new secret code, invalidating the one people were given.
     *
     * `administer`, like sharing: an editor writes the pages, and the code is
     * what lets somebody read the values the editor cannot (NotebookPolicy).
     * There is no "revoke" twin — a caderno always has a code, because a page
     * can grow a protected value at any moment and a null code would answer
     * every attempt as a wrong guess.
     */
    public function rotateSecretCode(Notebook $notebook): JsonResponse
    {
        $this->authorize('administer', $notebook);

        $notebook->rotateSecretCode();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Novo código gerado. O código anterior deixou de funcionar.',
            'updatableSlots' => [SharePanel::slot($notebook->fresh())],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'caderno';
        $slug = $base;
        $suffix = 1;

        while (in_array($slug, self::RESERVED_SLUGS, true) || Notebook::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
