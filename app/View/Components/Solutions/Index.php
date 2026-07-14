<?php

namespace App\View\Components\Solutions;

use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\Component;

/**
 * Lista do catálogo de soluções (F1), renderizável como slot atualizável
 * (`solutions-index-slot`). Busca por nome/fornecedor/responsável e filtros por
 * categoria, diretoria, jornada, hospedagem, contrato, status e "sem documentação".
 */
class Index extends Component
{
    use Renderable;

    public const DOM_ID = 'solutions-index-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.index', [
            'domId'     => self::DOM_ID,
            'solutions' => $this->query()->get(),
            'filters'   => $this->filters,
        ]);
    }

    private function query(): Builder
    {
        $f = $this->filters;

        return Solution::query()
            ->filter($f)
            ->with('vendor:id,name,slug,logo_path')
            ->withIntegrationCounts()
            ->withExists('documentedPages as has_docs')
            ->when(
                ($f['sort'] ?? null) === 'status',
                fn (Builder $q) => $q->orderBy('status')->orderBy('name'),
                fn (Builder $q) => $q->orderBy('name'),
            );
    }
}
