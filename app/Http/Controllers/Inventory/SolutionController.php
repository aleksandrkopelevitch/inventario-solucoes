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
    /** Catálogo (F1). Mesma action HTML/JSON: JSON devolve o slot filtrado. */
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

    /** Detalhe (header — 9.2 itens 1 e 2). Integrações/grafo/cobertura são etapas 3/4/6. */
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

    /** Busca por nome para o autocomplete dos chips de "Sistemas" no form de Pessoa. */
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
     * Edição inline de um único atributo (Categoria, Status, Criticidade, …)
     * diretamente no card do cabeçalho de detalhe (`Solutions\DetailHeader`) —
     * cada select do card autopersiste no `change`, sem abrir o painel de
     * edição completo.
     */
    public function updateAttributes(UpdateSolutionAttributesRequest $request, Solution $solution): JsonResponse
    {
        $solution->update($request->validated());

        return response()->json([
            'type'           => 'success',
            'message'        => 'Atributo atualizado.',
            'updatableSlots' => [DetailHeader::slot($solution), SolutionsIndex::slot()],
        ]);
    }

    /**
     * Conteúdo do side panel (form de criação/edição). `$filters` são os
     * filtros ativos na página de listagem que abriu o painel — repassados
     * pela URL para a action do form, e daí de volta para `saved()`, para
     * que o slot atualizado após salvar preserve os filtros em vigor.
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

        // Colunas NOT NULL sem default: mantém o valor enviado, senão o atual (edição), senão um
        // default fixo — precisa sempre existir como opção seedada em `attribute_options`.
        $data['support_type'] = $data['support_type'] ?? $solution?->support_type ?? 'third_party';
        $data['contract_status'] = $data['contract_status'] ?? $solution?->contract_status ?? 'unknown';

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('solution-logos', 'public');
        }

        return $data;
    }

    /**
     * Devolve o slot da listagem sempre e, quando a mutação partiu do
     * detalhe de uma solução existente, também o slot do cabeçalho — o
     * `ajax-slot.js` ignora silenciosamente o que não estiver na página
     * atual, então é seguro mandar os dois independentemente de onde o
     * painel foi aberto.
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
