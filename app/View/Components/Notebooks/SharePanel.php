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
 *
 * TWO audiences are decided here, and putting them side by side is the point:
 * the internal knowledge base (`/docs`, any Leo account) and the magic link
 * (no account at all). They are routinely mistaken for each other, and an admin
 * reaching for "compartilhar" almost always means the first. The knowledge-base
 * switch posts to `KnowledgeBaseSettingsController::update()`, which answers
 * with this slot AND with the settings list — the other screen that can flip
 * the same column.
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
            // The other audience. Same panel, different switch — see the
            // docblock: the two are constantly confused, so they are stated
            // side by side rather than one of them living on another screen.
            'isPublished'        => $this->notebook->isPublished(),
            'knowledgeBaseUrl'   => $this->notebook->knowledgeBaseUrl(),
            'publicationUrl'     => route('notebooks.publication', $this->notebook),
            'publicationConfirm' => $this->notebook->isPublished()
                ? 'Tirar este caderno da base de conhecimento? Ele deixa de aparecer em /docs para todo mundo da Leo.'
                : 'Publicar este caderno? Qualquer pessoa logada com a conta Leo Madeiras vai poder lê-lo em /docs.',
        ]);
    }
}
