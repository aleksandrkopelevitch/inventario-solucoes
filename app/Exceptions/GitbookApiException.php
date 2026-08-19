<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A GitBook API call that didn't answer with content we can use.
 *
 * Self-contained (see CLAUDE.md § Error Handling): it authors its own
 * message, because the only consumer is an artisan command whose whole job is
 * to print a line a human can act on — "the token has no access to this
 * space" is worth saying, `ClientException: 403` is not.
 */
class GitbookApiException extends RuntimeException
{
    public static function missingToken(): self
    {
        return new self(
            'GITBOOK_API_TOKEN is not set. Create a personal token with the `space:read` '
            . 'scope in GitBook › Developer settings and put it in your environment file.'
        );
    }

    public static function fromResponse(string $path, Response $response): self
    {
        $status = $response->status();

        $hint = match ($status) {
            401     => 'the token is invalid or expired',
            403     => 'the token has no access to this resource (needs the `space:read` scope)',
            404     => 'no such id — check it against `php artisan gitbook:import --list`',
            429     => 'rate-limited by GitBook; wait and re-run (the import is resumable)',
            default => 'unexpected status',
        };

        // GitBook answers errors as {error: {message}}; fall back to the raw
        // body, truncated, when it doesn't.
        $detail = $response->json('error.message') ?? str($response->body())->limit(200)->value();

        return new self("GitBook API {$status} on {$path} — {$hint}. {$detail}");
    }
}
