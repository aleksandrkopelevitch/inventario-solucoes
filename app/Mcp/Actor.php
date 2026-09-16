<?php

namespace App\Mcp;

use App\Models\McpToken;
use App\Models\User;

/**
 * WHO is calling the MCP server — the one thing every tool needs to know and
 * the only thing that differs between the server's two credentials.
 *
 * There are two, and they answer two different questions. A bearer token
 * (`McpToken`) authenticates a PROGRAM an admin deliberately handed a key to:
 * Claude Code, a script, anything with no browser to consent in. An OAuth access
 * token authenticates a PERSON who signed in — the path a non-technical
 * colleague takes, where the whole configuration is pasting one URL into a
 * connector dialog and logging in.
 *
 * Making them one object is what keeps `McpServer` and the twelve tools from
 * ever asking which credential arrived. They ask this instead, and there is
 * exactly one question worth asking:
 *
 * **`canReadInventory()` mirrors the app, not the credential.** Before OAuth,
 * who could connect was "whoever the admin minted a token for", so what a
 * connection reached could be a constant. Signing in is a door every Leo
 * account already has — including the `Reader` tier Entra SSO provisions, which
 * in the browser sees `/docs` and nothing else (App\Http\Middleware\
 * EnsureInventoryAccess). A connector that handed that same account every
 * vendor contact in the catalog would not be a new feature, it would be a hole
 * in the one the app already has. So a Reader's connection reaches the
 * published cadernos — exactly what its browser reaches — and every other tier
 * reaches the catalog, as before.
 *
 * A token stays full-access because it is not a tier: an admin minted it, named
 * it after what would hold it, and can delete it. That is the same decision
 * `McpToken` has always documented.
 */
final readonly class Actor
{
    private function __construct(
        public ?McpToken $token,
        public ?User $user,
        public bool $canReadInventory,
    ) {}

    /** A program holding a minted token: the full read, as it has always been. */
    public static function fromToken(McpToken $token): self
    {
        return new self($token, null, true);
    }

    /** A person who signed in: whatever their role reaches in the app itself. */
    public static function fromUser(User $user): self
    {
        return new self(null, $user, $user->role->canReadInventory());
    }

    /**
     * How the rate limiter and the logs name this caller.
     *
     * Keyed on the CREDENTIAL rather than the IP for the reason the limiter
     * already documented: every request from a given chat product arrives from
     * that product's egress range, so an IP bucket is shared by unrelated
     * callers and split for one caller on two devices.
     */
    public function rateLimitKey(): string
    {
        return $this->token
            ? 'mcp-token:' . $this->token->getKey()
            : 'mcp-user:' . $this->user?->getKey();
    }

    /** What the connection is called on screen and in a log line. */
    public function label(): string
    {
        return $this->token?->name ?? (string) $this->user?->name;
    }
}
