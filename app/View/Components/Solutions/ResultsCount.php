<?php

namespace App\View\Components\Solutions;

use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Contador ao vivo ("N soluções") ao lado do título do catálogo (F1) —
 * slot próprio para poder ser atualizado junto do grid a cada filtro/busca
 * sem recarregar a página, mesmo estando fora do container do grid no DOM.
 */
class ResultsCount extends Component
{
    use Renderable;

    public const DOM_ID = 'solutions-count-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.results-count', [
            'domId' => self::DOM_ID,
            'count' => Solution::query()->filter($this->filters)->count(),
        ]);
    }
}
