<?php

namespace App\Mcp;

use App\Enums\AccessModule;
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
 * ever asking which credential arrived.
 *
 * **`canRead()` mirrors the APP, not the credential.** A person's connection
 * reaches exactly the modules their account reaches in the browser — a module
 * at level None (App\Enums\AccessLevel) has no tools on it — because signing
 * in is a door every Leo account has, and a connector that handed out more
 * than the app does would be a hole in it. The published knowledge base is
 * open to every account, as `/docs` is.
 *
 * A token is not an account and keeps the full read: an admin minted it, named
 * it after what would hold it, and can delete it. That is the same decision
 * `McpToken` has always documented.
 */
final readonly class Actor
{
    private function __construct(
        public ?McpToken $token,
        public ?User $user,
        public bool $writeGranted = false,
        public ?string $clientId = null,
    ) {}

    /** A program holding a minted token. */
    public static function fromToken(McpToken $token): self
    {
        return new self($token, null);
    }

    /**
     * A person who signed in through the connector. `$writeGranted` is whether
     * their access token carries the `mcp:write` scope — what they authorized
     * on the consent screen, as opposed to what their account could do.
     * `$clientId` is the Passport client (the connector) the token was issued
     * to — what the write log groups a chat's changes by.
     */
    public static function fromUser(User $user, bool $writeGranted = false, ?string $clientId = null): self
    {
        return new self(null, $user, $writeGranted, $clientId);
    }

    /** Whether this caller may read `$module`; null is the published knowledge base. */
    public function canRead(?AccessModule $module): bool
    {
        return $module === null || $this->user === null || $this->user->canView($module);
    }

    /**
     * Whether this caller may CHANGE `$module`. All three must hold:
     *
     * - it is a PERSON. A token never writes: it authenticates a program, so a
     *   write made with one would carry no author and pass no policy;
     * - the person authorized writing (`mcp:write`) on this connection. A
     *   connection approved when the consent screen said "Ler" stays a reader;
     * - the person is an Editor of the module — asked live, so a demotion
     *   takes effect on the next request rather than at the next sign-in.
     */
    public function canWrite(?AccessModule $module): bool
    {
        return $module !== null
            && $this->user !== null
            && $this->writeGranted
            && $this->user->canEdit($module);
    }

    /**
     * Whether this is an Editor somewhere whose connection was NOT authorized
     * to write — the one caller the server should tell how to enable it.
     */
    public function couldWriteIfGranted(): bool
    {
        return $this->user !== null
            && ! $this->writeGranted
            && McpScopeRepository::mayWrite($this->user);
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
