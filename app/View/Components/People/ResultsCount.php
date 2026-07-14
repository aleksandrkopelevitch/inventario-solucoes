<?php

namespace App\View\Components\People;

use App\Models\Person;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Contador ao vivo ("N pessoas") ao lado do título do catálogo — slot
 * próprio para poder ser atualizado junto da lista a cada filtro/busca sem
 * recarregar a página, mesmo estando fora do container da lista no DOM.
 */
class ResultsCount extends Component
{
    use Renderable;

    public const DOM_ID = 'people-count-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.people.results-count', [
            'domId' => self::DOM_ID,
            'count' => Person::query()->filter($this->filters)->count(),
        ]);
    }
}
