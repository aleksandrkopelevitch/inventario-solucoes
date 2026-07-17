<?php

namespace App\View\Components\Solutions;

use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Compartilhar" panel in the documentation toolbar (only in the Solution's
 * own docs, admin only). Generates/revokes the documentation's public link
 * ("magic link") — opaque token in `solutions.public_token`. Generating and
 * revoking both return this updated slot (`docs-share-slot`) via `docs-share.js`.
 */
class SharePanel extends Component
{
    use Renderable;

    public const DOM_ID = 'docs-share-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.share-panel', [
            'domId'     => self::DOM_ID,
            'publicUrl' => $this->solution->publicDocsUrl(),
        ]);
    }
}
