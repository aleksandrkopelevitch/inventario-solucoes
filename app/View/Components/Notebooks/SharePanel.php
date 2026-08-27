<?php

namespace App\View\Components\Notebooks;

use App\Models\Notebook;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Compartilhar" panel in the documentation toolbar (admin only).
 * Generates/revokes the caderno's public link ("magic link") — an opaque token
 * in `notebooks.public_token`. Generating and revoking both return this updated
 * slot (`docs-share-slot`) via `docs-share.js`.
 *
 * What is shared is the CADERNO, whatever it happens to be linked to: linking a
 * notebook to a solution publishes nothing, and unlinking it un-publishes
 * nothing. Sharing is always a deliberate gesture on this panel.
 */
class SharePanel extends Component
{
    use Renderable;

    public const DOM_ID = 'docs-share-slot';

    public function __construct(public Notebook $notebook) {}

    public static function slot(Notebook $notebook): array
    {
        return (new static($notebook))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.notebooks.share-panel', [
            'domId'     => self::DOM_ID,
            'publicUrl' => $this->notebook->publicDocsUrl(),
        ]);
    }
}
