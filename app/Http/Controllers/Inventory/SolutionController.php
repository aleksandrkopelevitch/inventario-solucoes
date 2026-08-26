<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSolutionPersonRequest;
use App\Http\Requests\StoreSolutionRequest;
use App\Http\Requests\UpdateSolutionAttributesRequest;
use App\Http\Requests\UpdateSolutionFieldRequest;
use App\Http\Requests\UpdateSolutionPersonRequest;
use App\Http\Requests\UpdateSolutionRequest;
use App\Models\AttributeOption;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Services\SolutionCatalogStatsService;
use App\View\Components\Solutions\DetailHeader;
use App\View\Components\Solutions\FilterChips;
use App\View\Components\Solutions\Index as SolutionsIndex;
use App\View\Components\Solutions\ResultsCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SolutionController extends Controller
{
    /** Catalog (F1). Same HTML/JSON action: JSON returns the filtered slot. */
    public function index(Request $request, SolutionCatalogStatsService $stats)
    {
        $this->authorize('viewAny', Solution::class);

        $filters = (array) $request->input('filter', []);

        if ($request->wantsJson()) {
            return response()->json(['updatableSlots' => [
                SolutionsIndex::slot($filters),
                ResultsCount::slot($filters),
                FilterChips::slot($filters),
            ]]);
        }

        return view('solutions.index', [
            'filters'          => $filters,
            'categories'       => AttributeOption::options('category'),
            'environments'     => AttributeOption::options('environment'),
            'contractStatuses' => AttributeOption::options('contract_status'),
            'statuses'         => AttributeOption::options('status'),
            'directorates'     => AttributeOption::options('directorate'),
            // Unfiltered "whole catalog" summary for the hero stat-strip —
            // never changes with the filters/grid below it.
            'catalogStats' => $stats->summary(),
        ]);
    }

    /** Detail (header — 9.2 items 1 and 2). Diagrams/graph/coverage are steps 3/4/6. */
    public function show(Request $request, Solution $solution)
    {
        $this->authorize('view', $solution);

        $solution->load([
            'vendor:id,name,slug,logo_path,website',
            'people' => fn ($q) => $q->with('company:id,name,slug'),
        ]);

        if ($request->wantsJson()) {
            return response()->json(['solution' => $solution]);
        }

        return view('solutions.show', [
            'solution' => $solution,
            // A committee approved a topology for this solution and nobody
            // applied it yet: the diagrams list below is showing the
            // previous scenario, and saying so is the whole point of tracking
            // it (App\Models\ApprovedTopology). Resolved here rather than in
            // the view — it is a query.
            'pendingTopologies' => $solution->pendingTopologies()
                ->with('submission:id,name,slug')
                ->orderBy('approved_at')
                ->get(),
        ]);
    }

    /** Search by name for the "Systems" chips autocomplete in the Person form. */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Solution::class);

        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        $results = Solution::query()
            ->where('name', 'like', '%' . $term . '%')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'category'])
            ->map(fn (Solution $solution) => [
                'id'   => $solution->id,
                'name' => $solution->name,
                'meta' => $solution->category_label,
            ]);

        return response()->json(['results' => $results]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->authorize('create', Solution::class);

        return $this->panel(new Solution, (array) $request->query('filter', []));
    }

    public function edit(Request $request, Solution $solution): JsonResponse
    {
        $this->authorize('update', $solution);

        return $this->panel($solution, (array) $request->query('filter', []));
    }

    public function store(StoreSolutionRequest $request): JsonResponse
    {
        Solution::create($this->payload($request));

        return $this->saved('Solução criada com sucesso.', null, (array) $request->query('filter', []));
    }

    public function update(UpdateSolutionRequest $request, Solution $solution): JsonResponse
    {
        $solution->update($this->payload($request, $solution));

        return $this->saved('Solução atualizada com sucesso.', $solution, (array) $request->query('filter', []));
    }

    /**
     * One of the solution's OWN fields (name, description, vendor, logo,
     * support note), edited in place on the detail header
     * (`<x-ui.inline-edit>`) instead of through the whole panel. The 8
     * attribute badges on the same header have their own endpoint below.
     */
    public function updateField(UpdateSolutionFieldRequest $request, Solution $solution): JsonResponse
    {
        $data = $request->safe()->except(['logo', 'logo_action']);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('solution-logos', 'public');
        } elseif ($request->input('logo_action') === 'remove') {
            // "Remover" on the `<x-forms.image-upload>` tile. The stored file
            // itself is left on disk on purpose: `logo_path` is a plain column
            // (not MediaLibrary), and nothing else in this app deletes an
            // orphaned upload either — doing it here alone would be the odd one
            // out, and a wrong click would be unrecoverable.
            $data['logo_path'] = null;
        }

        $solution->update($data);

        return response()->json([
            'type'    => 'success',
            'message' => 'Alteração salva.',
            // Only the detail header reflects this — there's no
            // solutions-index-slot on this (detail) page for the catalog list
            // and its widgets to land in.
            'updatableSlots' => [DetailHeader::slot($solution)],
        ]);
    }

    /**
     * The solution's owners (the `person_solution` pivot), linked / unlinked in
     * place on the header's owners grid. Mirror of
     * `PersonController::storeSolution/destroySolution` on the other side of the
     * same pivot — the role decides which of the three columns the person lands
     * in, and re-roling stays on the person's page, where the role is a badge
     * of its own.
     */
    public function attachPerson(StoreSolutionPersonRequest $request, Solution $solution): JsonResponse
    {
        $solution->people()->attach($request->validated('person_id'), ['role' => $request->validated('role')]);

        return $this->ownersSaved($solution, 'Pessoa vinculada.');
    }

    /**
     * Swaps WHO holds one of the three roles, without unlinking and relinking.
     * Mirror of `PersonController::updateSolution`: the pivot's identity IS the
     * (person, solution) pair, so there's no row to update in place — detach +
     * attach, carrying over what the link already held (its role, unless this
     * same request changes it, and `is_primary`; dropping either would be a
     * silent second edit nobody asked for). `$person` comes from a scoped
     * binding, so its pivot is already hydrated.
     */
    public function updatePerson(UpdateSolutionPersonRequest $request, Solution $solution, Person $person): JsonResponse
    {
        $role = $request->validated('role');
        $targetId = (int) $request->validated('person_id', $person->getKey());

        if ($targetId !== $person->getKey()) {
            $solution->people()->detach($person->getKey());
            $solution->people()->attach($targetId, [
                'role'       => $role ?? $person->pivot->role,
                'is_primary' => $person->pivot->is_primary,
            ]);

            return $this->ownersSaved($solution, 'Pessoa alterada.');
        }

        if ($role) {
            $solution->people()->updateExistingPivot($person->getKey(), ['role' => $role]);
        }

        return $this->ownersSaved($solution, 'Alteração salva.');
    }

    public function detachPerson(Request $request, Solution $solution, Person $person): JsonResponse
    {
        $this->authorize('update', $solution);

        $solution->people()->detach($person->getKey());

        return $this->ownersSaved($solution, 'Pessoa desvinculada.');
    }

    /**
     * The owners grid lives inside `Solutions\DetailHeader`, so that whole slot
     * is the answer. The explicit `load()` matters: the component renders the
     * grid off `people`, and its `loadMissing()` would keep the copy loaded
     * before the mutation.
     */
    private function ownersSaved(Solution $solution, string $message): JsonResponse
    {
        $solution->load(['people' => fn ($q) => $q->with('company:id,name,slug')]);

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => [DetailHeader::slot($solution)],
        ]);
    }

    /**
     * Inline editing of a single attribute (Category, Status, Criticality, …)
     * directly in the detail header card (`Solutions\DetailHeader`) — the badge
     * is read-only text until it's double-clicked, then a `<x-ui.inline-edit>`
     * select, confirmed one attribute at a time. Same gesture as the header's
     * own columns (`updateField` above); a separate endpoint because these are
     * `attribute_options` values validated per group.
     */
    public function updateAttributes(UpdateSolutionAttributesRequest $request, Solution $solution): JsonResponse
    {
        $solution->update($request->validated());

        return response()->json([
            'type'    => 'success',
            'message' => 'Atributo atualizado.',
            // Only the detail-header badges reflect this — there is no
            // solutions-index-slot on this (detail) page for the catalog
            // list/ResultsCount/FilterChips to land in.
            'updatableSlots' => [DetailHeader::slot($solution)],
        ]);
    }

    /**
     * Side panel content (create/edit form). `$filters` are the filters
     * active on the listing page that opened the panel — carried through
     * the URL to the form's action, and from there back to `saved()`, so
     * the slot updated after saving preserves the filters in effect.
     */
    private function panel(Solution $solution, array $filters = []): JsonResponse
    {
        return response()->json([
            'content' => view('solutions.form', [
                'solution'         => $solution,
                'filters'          => $filters,
                'companies'        => Company::orderBy('name')->get(['id', 'name']),
                'categories'       => AttributeOption::options('category'),
                'directorates'     => AttributeOption::options('directorate'),
                'supportTypes'     => AttributeOption::options('support_type'),
                'environments'     => AttributeOption::options('environment'),
                'clouds'           => AttributeOption::options('cloud'),
                'contractStatuses' => AttributeOption::options('contract_status'),
                'criticalities'    => AttributeOption::options('criticality'),
                'statuses'         => AttributeOption::options('status'),
            ])->render(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(StoreSolutionRequest|UpdateSolutionRequest $request, ?Solution $solution = null): array
    {
        // `logo_action` is a UI instruction, not a column — leaving it in the
        // array throws under `Model::shouldBeStrict()`
        // (`preventSilentlyDiscardingAttributes`), which is a 500 in dev/test.
        $data = $request->safe()->except(['logo', 'logo_action']);

        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($solution?->slug ?? $this->uniqueSlug($data['name'], $solution));

        // NOT NULL columns with no default: keep the submitted value, else the current one (editing), else a
        // fixed default — must always exist as a seeded option in `attribute_options`.
        $data['support_type'] = $data['support_type'] ?? $solution?->support_type ?? 'third_party';
        $data['contract_status'] = $data['contract_status'] ?? $solution?->contract_status ?? 'unknown';

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('solution-logos', 'public');
        } elseif ($request->input('logo_action') === 'remove') {
            // The form's `<x-forms.image-upload>` has always rendered a
            // "Remover" button writing this hidden field; nothing read it, so
            // it silently did nothing. Same handling as `updateField()`.
            $data['logo_path'] = null;
        }

        return $data;
    }

    /**
     * Always returns the listing slot and, when the mutation originated
     * from an existing solution's detail page, also the header slot — the
     * `ajax-slot.js` silently ignores whatever isn't on the current page,
     * so it's safe to send both regardless of where the panel was opened.
     */
    private function saved(string $message, ?Solution $solution = null, array $filters = []): JsonResponse
    {
        $slots = [SolutionsIndex::slot($filters), ResultsCount::slot($filters), FilterChips::slot($filters)];

        if ($solution) {
            $slots[] = DetailHeader::slot($solution);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => $slots,
            'js'             => "document.querySelector('[data-ak-panel-close]')?.click()",
        ]);
    }

    private function uniqueSlug(string $name, ?Solution $solution): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Solution::where('slug', $slug)
            ->when($solution, fn ($q) => $q->whereKeyNot($solution->getKey()))
            ->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
