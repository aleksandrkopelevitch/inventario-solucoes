<?php

namespace App\View\Components\Mcp;

use App\Models\McpToken;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The minted tokens, and — exactly once — the plaintext of one just created.
 *
 * `$plain` is why this takes a parameter at all. A token exists in readable form
 * for the length of one response (`McpToken::mint()`), so the screen that prints
 * it is the only chance anybody gets, and it has to be printed somewhere a
 * person can select and copy from. Threading it through the SLOT rather than
 * through a Toast is what makes that work: the creation response re-renders this
 * list with the value in it, and the next render of the same list — a delete, a
 * reload, anything — has no `$plain` and therefore cannot print it again.
 *
 * Nothing persists it in between. There is no flash message and no session key,
 * because both survive a redirect the person did not ask for and would put a
 * live credential in the session store to save one screen.
 */
class TokenList extends Component
{
    use Renderable;

    public const DOM_ID = 'mcp-tokens-slot';

    public function __construct(public readonly ?string $plain = null) {}

    public static function slot(?string $plain = null): array
    {
        return (new static($plain))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.mcp.token-list', [
            'domId' => self::DOM_ID,
            'plain' => $this->plain,
            // `createdBy` eager-loaded: this is a multi-row hydration, which is
            // where strict mode arms (§ Strict mode).
            'tokens' => McpToken::query()
                ->with('createdBy:id,name')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}
