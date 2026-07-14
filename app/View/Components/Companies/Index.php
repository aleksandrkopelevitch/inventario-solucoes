<?php

namespace App\View\Components\Companies;

use App\Models\Company;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\Component;

/**
 * Lista de empresas (F5), slot atualizável `companies-index-slot`. Busca por
 * nome e filtro por tipo (interno/fornecedor/parceiro).
 */
class Index extends Component
{
    use Renderable;

    public const DOM_ID = 'companies-index-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.companies.index', [
            'domId'     => self::DOM_ID,
            'companies' => $this->query()->get(),
            'filters'   => $this->filters,
        ]);
    }

    private function query(): Builder
    {
        return Company::query()
            ->filter($this->filters)
            ->withCount(['people', 'providedSolutions'])
            ->orderBy('name');
    }
}
