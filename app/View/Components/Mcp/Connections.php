<?php

namespace App\View\Components\Mcp;

use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The MCP connections the signed-in account has authorized — one row per client
 * that went through the consent screen.
 *
 * A slot rather than a plain partial because revoking is an AJAX post that has
 * to leave the rest of the page alone: the connect screen is mostly a URL
 * somebody is in the middle of copying (§ Updatable slots).
 *
 * Only LIVE tokens are listed. A revoked or expired one is not a connection a
 * person can act on — the button beside it would do nothing — and listing it
 * would make "você tem três conexões" false in the direction that matters.
 */
class Connections extends Component
{
    use Renderable;

    public const DOM_ID = 'mcp-connections-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.mcp.connections', [
            'domId' => self::DOM_ID,
            // `client` eager-loaded: a multi-row hydration is exactly where
            // strict mode arms (§ Strict mode).
            'connections' => auth()->user()?->tokens()
                ->with('client:id,name')
                ->where('revoked', false)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByDesc('created_at')
                ->get() ?? collect(),
        ]);
    }
}
