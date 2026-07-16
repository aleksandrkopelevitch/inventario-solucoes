<?php

namespace App\View\Components\Documentation;

use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Lista de documentos de contexto de uma Solução, renderizável como slot
 * atualizável (`context-documents-slot`) — o painel do "Assiste IA" a exibe e
 * os endpoints de upload/remover (SolutionContextDocumentController) a devolvem
 * como `updatableSlots` para refrescar sem recarregar o painel.
 */
class ContextDocuments extends Component
{
    use Renderable;

    public const DOM_ID = 'context-documents-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.context-documents', [
            'domId'     => self::DOM_ID,
            'solution'  => $this->solution,
            'documents' => $this->solution->getMedia(Solution::CONTEXT_COLLECTION),
        ]);
    }
}
