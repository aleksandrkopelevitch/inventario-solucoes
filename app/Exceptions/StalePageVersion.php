<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A save was based on a version of the page that is no longer the stored one:
 * somebody else — another tab, another person, a chat through the MCP — saved
 * in between. Refusing is the whole point; the alternative is the later save
 * silently erasing the earlier one (App\Actions\Documentation\SavePageContent).
 *
 * 409 rather than 422: nothing about the request is malformed, the page moved.
 * The editor stops autosaving on it and keeps the person's text on screen.
 */
class StalePageVersion extends RuntimeException
{
    public function __construct(public readonly string $currentVersion)
    {
        parent::__construct('Esta página foi alterada em outro lugar (outra aba, outra pessoa ou o MCP) desde que você a abriu. '
            . 'Copie o que você escreveu e recarregue a página para continuar a partir da versão atual.');
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $this->getMessage(), 'title' => 'Conflito', 'type' => 'warning', 'conflict' => true], 409)
            : null;
    }
}
