<?php

namespace App\View\Components\Solutions;

use App\Models\AttributeOption;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Chips for the active filters in the catalog (F1), each individually removable.
 * Own slot (not part of the grid) to update alongside each filter/search.
 */
class FilterChips extends Component
{
    use Renderable;

    public const DOM_ID = 'solutions-chips-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.filter-chips', [
            'domId' => self::DOM_ID,
            'chips' => $this->chips(),
        ]);
    }

    /** @return array<int, array{field: string, label: ?string, value: string}> */
    private function chips(): array
    {
        $f = $this->filters;
        $chips = [];

        if (filled($f['search'] ?? null)) {
            $chips[] = ['field' => 'filter[search]', 'label' => 'Busca', 'value' => $f['search']];
        }

        if (filled($f['category'] ?? null)) {
            $chips[] = ['field' => 'filter[category]', 'label' => 'Categoria', 'value' => AttributeOption::labelFor('category', $f['category']) ?? $f['category']];
        }

        if (filled($f['directorate'] ?? null)) {
            $chips[] = ['field' => 'filter[directorate]', 'label' => 'Diretoria', 'value' => $f['directorate']];
        }

        if (filled($f['environment'] ?? null)) {
            $chips[] = ['field' => 'filter[environment]', 'label' => 'Hospedagem', 'value' => AttributeOption::labelFor('environment', $f['environment']) ?? $f['environment']];
        }

        if (filled($f['contract'] ?? null)) {
            $chips[] = ['field' => 'filter[contract]', 'label' => 'Contrato', 'value' => AttributeOption::labelFor('contract_status', $f['contract']) ?? $f['contract']];
        }

        if (filled($f['status'] ?? null)) {
            $chips[] = ['field' => 'filter[status]', 'label' => 'Status', 'value' => AttributeOption::labelFor('status', $f['status']) ?? $f['status']];
        }

        if ($f['undocumented'] ?? false) {
            $chips[] = ['field' => 'filter[undocumented]', 'label' => null, 'value' => 'Sem documentação'];
        }

        return $chips;
    }
}
