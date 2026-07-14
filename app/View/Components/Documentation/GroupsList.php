<?php

namespace App\View\Components\Documentation;

use App\Services\DocumentationCoverageService;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Lista dos Grupos ("Aninhamentos") standalone no hub de Documentação — fora
 * do filtro de busca/status de soluções e integrações (não têm cobertura em
 * %, só uma listagem simples). Slot próprio pra refletir criar/renomear/
 * excluir sem precisar recarregar a página inteira.
 */
class GroupsList extends Component
{
    use Renderable;

    public const DOM_ID = 'documentation-groups-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.groups-list', [
            'domId'  => self::DOM_ID,
            'groups' => app(DocumentationCoverageService::class)->groupsList(),
        ]);
    }
}
