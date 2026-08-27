<?php

namespace App\View\Components\Documentation;

use App\Models\Notebook;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * List of a caderno's context documents, renderable as an updatable slot
 * (`context-documents-slot`) — the "Assiste IA" panel displays it and the
 * upload/remove endpoints (NotebookContextDocumentController) return it as
 * `updatableSlots` to refresh without reloading the panel.
 */
class ContextDocuments extends Component
{
    use Renderable;

    public const DOM_ID = 'context-documents-slot';

    public function __construct(public Notebook $notebook) {}

    public static function slot(Notebook $notebook): array
    {
        return (new static($notebook))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.context-documents', [
            'domId'     => self::DOM_ID,
            'notebook'  => $this->notebook,
            'documents' => $this->notebook->getMedia(Notebook::CONTEXT_COLLECTION),
        ]);
    }
}
