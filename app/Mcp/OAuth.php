<?php

namespace App\Mcp;

/**
 * The constants the OAuth half of the MCP server agrees on.
 *
 * Two scopes, and they mean different kinds of thing:
 *
 * - **`mcp` is a LABEL.** What a connection READS is decided by the account's
 *   module levels (`App\Mcp\Actor::canRead()`), not by a string the client
 *   asked for. Passport needs a scope to exist so the consent screen has
 *   something to name and the token has something to carry.
 * - **`mcp:write` is a real permission**, the one place the token's scopes
 *   decide anything. A person whose connection was approved when the consent
 *   screen said "Ler" did not agree to have their chat client create and edit
 *   records in their name, so writing waits until they authorize it. Even then
 *   it is ANDed with the account's own Editor level (`Actor::canWrite()`), and
 *   an account that is an Editor nowhere never receives the scope
 *   (`McpScopeRepository`).
 */
final class OAuth
{
    public const SCOPE = 'mcp';

    /** What the consent screen says this scope allows. */
    public const SCOPE_DESCRIPTION = 'Ler o inventário e a documentação publicada';

    public const WRITE_SCOPE = 'mcp:write';

    public const WRITE_SCOPE_DESCRIPTION = 'Criar e editar registros do inventário e cadernos em seu nome';

    /** Every scope this server advertises, in the order a client should ask for them. */
    public const SCOPES = [self::SCOPE, self::WRITE_SCOPE];
}
