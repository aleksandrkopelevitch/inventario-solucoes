<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\CompanyKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\View\Components\Companies\DetailHeader;
use App\View\Components\Companies\FilterChips;
use App\View\Components\Companies\Index as CompaniesIndex;
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
     * `$filters` são os filtros ativos na listagem que abriu o painel —
     * repassados pela URL até `saved()`, para o slot atualizado após salvar
     * preservar os filtros em vigor.
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
        $data = $request->safe()->except(['logo']);

        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($company?->slug ?? $this->uniqueSlug($data['name'], $company));

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
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
