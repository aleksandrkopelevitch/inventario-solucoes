<?php

namespace App\Mcp;

/**
 * The constants the OAuth half of the MCP server agrees on.
 *
 * One scope, named `mcp`, and it is a LABEL rather than a permission: what a
 * connection reaches is decided by the account's role (`App\Mcp\Actor`), not by
 * a string the client asked for. Passport needs a scope to exist so the consent
 * screen has something to name and the token has something to carry; inventing
 * a second one would promise a granularity the server does not implement.
 */
final class OAuth
{
    public const SCOPE = 'mcp';

    /** What the consent screen says this scope allows. */
    public const SCOPE_DESCRIPTION = 'Ler o inventário e a documentação publicada';
}
