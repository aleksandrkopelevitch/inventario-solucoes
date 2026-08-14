<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\CompanyKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyPersonRequest;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\StoreCompanySolutionRequest;
use App\Http\Requests\UpdateCompanyFieldRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\View\Components\Companies\DetailHeader;
use App\View\Components\Companies\FilterChips;
use App\View\Components\Companies\Index as CompaniesIndex;
use App\View\Components\Companies\People;
use App\View\Components\Companies\ProvidedSolutions;
use App\View\Components\Companies\ResultsCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Company::class);

        $filters = (array) $request->input('filter', []);

        if ($request->wantsJson()) {
            return response()->json(['updatableSlots' => [
                CompaniesIndex::slot($filters),
                ResultsCount::slot($filters),
                FilterChips::slot($filters),
            ]]);
        }

        return view('companies.index', [
            'filters' => $filters,
            'kinds'   => CompanyKind::cases(),
        ]);
    }

    public function show(Request $request, Company $company)
    {
        $this->authorize('view', $company);

        $company->load([
            'people' => fn ($q) => $q->with('contacts'),
            'providedSolutions:id,name,slug,vendor_company_id,category,status',
        ]);

        if ($request->wantsJson()) {
            return response()->json(['company' => $company]);
        }

        return view('companies.show', ['company' => $company]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        return $this->panel(new Company, (array) $request->query('filter', []));
    }

    public function edit(Request $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        return $this->panel($company, (array) $request->query('filter', []));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        Company::create($this->payload($request));

        return $this->saved('Empresa criada com sucesso.', null, (array) $request->query('filter', []));
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $company->update($this->payload($request, $company));

        return $this->saved('Empresa atualizada com sucesso.', $company, (array) $request->query('filter', []));
    }

    /**
     * One field, edited in place on the detail page (`<x-ui.inline-edit>`),
     * instead of through the whole panel. Same shape as
     * `PersonController::updateField()` / `SolutionController::updateField()`.
     */
    public function updateField(UpdateCompanyFieldRequest $request, Company $company): JsonResponse
    {
        $company->update($this->fieldPayload($request));

        return response()->json([
            'type'    => 'success',
            'message' => 'Alteração salva.',
            // Only the detail header reflects this — there's no
            // companies-index-slot on this (detail) page for the catalog list
            // and its widgets to land in.
            'updatableSlots' => [DetailHeader::slot($company)],
        ]);
    }

    /**
     * The company's two relations, attached / detached in place on their cards.
     * Both are plain HasMany: "linking" writes the child's foreign key, so
     * detaching leaves the person with no company (or the solution with no
     * vendor) — the record itself is never touched otherwise, and never deleted.
     */
    public function attachPerson(StoreCompanyPersonRequest $request, Company $company): JsonResponse
    {
        Person::whereKey($request->validated('person_id'))->update(['company_id' => $company->getKey()]);

        return $this->relationSaved($company, fn () => [People::slot($company)], 'Pessoa vinculada.');
    }

    public function detachPerson(Request $request, Company $company, Person $person): JsonResponse
    {
        $this->authorize('update', $company);

        $person->update(['company_id' => null]);

        return $this->relationSaved($company, fn () => [People::slot($company)], 'Pessoa desvinculada.');
    }

    public function attachSolution(StoreCompanySolutionRequest $request, Company $company): JsonResponse
    {
        Solution::whereKey($request->validated('solution_id'))->update(['vendor_company_id' => $company->getKey()]);

        return $this->relationSaved($company, fn () => [ProvidedSolutions::slot($company)], 'Sistema vinculado.');
    }

    /**
     * `$providedSolution`, not `$solution`: the route is scope-bound, and Laravel
     * resolves the child through the relation its param name pluralises to —
     * `providedSolutions()`, since Company has no `solutions()`.
     */
    public function detachSolution(Request $request, Company $company, Solution $providedSolution): JsonResponse
    {
        $this->authorize('update', $company);

        $providedSolution->update(['vendor_company_id' => null]);

        return $this->relationSaved($company, fn () => [ProvidedSolutions::slot($company)], 'Sistema desvinculado.');
    }

    /**
     * Answer for the relation mutations above. The slots are built through a
     * closure so they're always rendered AFTER the reload: each card reads the
     * relation that just changed, and the components use `loadMissing()`, which
     * would happily keep the copy loaded before the mutation.
     *
     * @param  \Closure(): array<int, array{id: string, content: string}>  $slots
     */
    private function relationSaved(Company $company, \Closure $slots, string $message): JsonResponse
    {
        $company->load(['people', 'providedSolutions']);

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => $slots(),
        ]);
    }

    /** @return array<string, mixed> */
    private function fieldPayload(UpdateCompanyFieldRequest $request): array
    {
        $data = $request->safe()->except(['logo', 'logo_action']);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
        } elseif ($request->input('logo_action') === 'remove') {
            // "Remover" on the `<x-forms.image-upload>` tile. The stored file
            // itself is left on disk on purpose: `logo_path` is a plain column
            // (not MediaLibrary), and nothing else in this app deletes an
            // orphaned upload either — doing it here alone would be the odd one
            // out, and a wrong click would be unrecoverable.
            $data['logo_path'] = null;
        }

        return $data;
    }

    /**
     * `$filters` are the filters active on the listing that opened the
     * panel — carried through the URL all the way to `saved()`, so the slot
     * updated after saving preserves the filters in effect.
     */
    private function panel(Company $company, array $filters = []): JsonResponse
    {
        return response()->json([
            'content' => view('companies.form', [
                'company' => $company,
                'filters' => $filters,
                'kinds'   => CompanyKind::cases(),
            ])->render(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(StoreCompanyRequest|UpdateCompanyRequest $request, ?Company $company = null): array
    {
        // `logo_action` is a UI instruction, not a column — leaving it in the
        // array throws under `Model::shouldBeStrict()`
        // (`preventSilentlyDiscardingAttributes`), which is a 500 in dev/test.
        $data = $request->safe()->except(['logo', 'logo_action']);

        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($company?->slug ?? $this->uniqueSlug($data['name'], $company));

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
        } elseif ($request->input('logo_action') === 'remove') {
            // The form's `<x-forms.image-upload>` has always rendered a
            // "Remover" button writing this hidden field; nothing read it, so
            // it silently did nothing. Same handling as `fieldPayload()`.
            $data['logo_path'] = null;
        }

        return $data;
    }

    private function saved(string $message, ?Company $company = null, array $filters = []): JsonResponse
    {
        $slots = [CompaniesIndex::slot($filters), ResultsCount::slot($filters), FilterChips::slot($filters)];

        if ($company) {
            $slots[] = DetailHeader::slot($company);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => $slots,
            'js'             => "document.querySelector('[data-ak-panel-close]')?.click()",
        ]);
    }

    private function uniqueSlug(string $name, ?Company $company): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Company::where('slug', $slug)
            ->when($company, fn ($q) => $q->whereKeyNot($company->getKey()))
            ->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
