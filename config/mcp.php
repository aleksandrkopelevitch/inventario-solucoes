<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Origens de redirecionamento aceitas no registro dinâmico
    |--------------------------------------------------------------------------
    |
    | Where an authorization code may be sent back to. This is the entire
    | security of the open `/oauth/register` endpoint (RFC 7591): a client
    | registers itself with no credential, so what stops a stranger minting a
    | client that redirects somebody's code to their own server is this list.
    |
    | Loopback (`http://localhost:<porta>`) is always accepted, and not by this
    | list — a desktop client picks a free port at runtime, so the port cannot be
    | written down here. See `App\Http\Requests\RegisterOAuthClientRequest`.
    |
    | Add a product here when somebody needs it, host by host. A wildcard would
    | turn the endpoint back into the thing the list exists to prevent.
    |
    */

    'redirect_origins' => array_values(array_filter(explode(',', (string) env('MCP_REDIRECT_ORIGINS', implode(',', [
        'https://claude.ai',
        'https://claude.com',
        'https://chatgpt.com',
    ]))))),

];
