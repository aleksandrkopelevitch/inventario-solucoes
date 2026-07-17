<?php

namespace App\View\Components\Documentation;

use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * List of a Solution's context documents, renderable as an updatable slot
 * (`context-documents-slot`) — the "Assiste IA" panel displays it and the
 * upload/remove endpoints (SolutionContextDocumentController) return it as
 * `updatableSlots` to refresh without reloading the panel.
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
