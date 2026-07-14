<?php

namespace App\View\Components\Documentation;

use App\Services\DocumentationCoverageService;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Lista do hub de Documentação, renderizável como slot atualizável
 * (`documentation-hub-slot`) — soluções + integrações agrupadas por solução,
 * cada uma com status de documentação (conteúdo real). Filtros por busca,
 * tipo e status via DocumentationCoverageService::groups().
 */
class Hub extends Component
{
    use Renderable;

    public const DOM_ID = 'documentation-hub-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.hub', [
            'domId'  => self::DOM_ID,
            'groups' => app(DocumentationCoverageService::class)->groups($this->filters),
        ]);
    }
}
