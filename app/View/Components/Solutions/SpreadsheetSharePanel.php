<?php

namespace App\View\Components\Solutions;

use App\Models\PublicLink;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Compartilhar" panel on the solutions spreadsheet (admin only): generates,
 * copies and revokes its magic link.
 *
 * The same panel as a caderno's link (`Notebooks\SharePanel`) and driven by the
 * same module (`docs-share.js`, through `data-ak-share-panel`), so the two
 * public links are generated, copied and revoked the same way. Only the magic
 * link lives here — the spreadsheet has no knowledge-base or secret-code
 * counterpart.
 */
class SpreadsheetSharePanel extends Component
{
    use Renderable;

    public const DOM_ID = 'solutions-sheet-share-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $link = PublicLink::for(PublicLink::SOLUTIONS_SPREADSHEET);

        return view('components.solutions.spreadsheet-share-panel', [
            'domId'     => self::DOM_ID,
            'publicUrl' => $link ? route('public.solutions.spreadsheet', $link->token) : null,
        ]);
    }
}
