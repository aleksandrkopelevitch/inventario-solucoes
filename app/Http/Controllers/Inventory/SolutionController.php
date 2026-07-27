<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSolutionRequest;
use App\Http\Requests\UpdateSolutionAttributesRequest;
use App\Http\Requests\UpdateSolutionRequest;
use App\Models\AttributeOption;
use App\Models\Company;
use App\Models\Solution;
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
    public function index(Request $request)
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
        ]);
    }

    /** Detail (header — 9.2 items 1 and 2). Integrations/graph/coverage are steps 3/4/6. */
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

        return view('solutions.show', ['solution' => $solution]);
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
     * Inline editing of a single attribute (Category, Status, Criticality, …)
     * directly in the detail header card (`Solutions\DetailHeader`) — each
     * select in the card auto-persists on `change`, without opening the full
     * edit panel.
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
        $data = $request->safe()->except(['logo']);

        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($solution?->slug ?? $this->uniqueSlug($data['name'], $solution));

        // NOT NULL columns with no default: keep the submitted value, else the current one (editing), else a
        // fixed default — must always exist as a seeded option in `attribute_options`.
        $data['support_type'] = $data['support_type'] ?? $solution?->support_type ?? 'third_party';
        $data['contract_status'] = $data['contract_status'] ?? $solution?->contract_status ?? 'unknown';

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('solution-logos', 'public');
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
