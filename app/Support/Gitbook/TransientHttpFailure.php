<?php

namespace App\Support\Gitbook;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Is this failure worth another attempt? Shared by both GitBook macros
 * (`Http::gitbook()` and `Http::gitbookAsset()`).
 *
 * A class rather than a method on AppServiceProvider for a reason worth
 * remembering: `Http::macro()` closures are **rebound** to the PendingRequest
 * (`Macroable::__call` does `$macro->bindTo($this, static::class)`), so `self::`
 * inside one resolves to `Illuminate\Http\Client\PendingRequest`, not to the
 * class the closure was written in — `Method PendingRequest::isTransient does
 * not exist`. An imported class name is compile-time resolved and immune to it.
 */
class TransientHttpFailure
{
    /** Statuses where the far side is saying "not now" rather than "no". */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /**
     * True when the request never really landed (DNS, connect, or read timeout)
     * or the server asked us to come back. A 4xx other than 429 is a real
     * answer — retrying it only makes the same mistake more slowly.
     */
    public static function matches(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && in_array($e->response->status(), self::RETRYABLE_STATUSES, true);
    }
}
