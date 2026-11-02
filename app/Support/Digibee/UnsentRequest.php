<?php

namespace App\Support\Digibee;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Did this failure happen BEFORE the platform could act on the request?
 *
 * The retry test for the Digibee writes that must not happen twice — creating
 * a pipeline and deploying one. `TransientHttpFailure` retries a read timeout
 * and a 500/502/504 too, which is right for a read and wrong here: a create
 * whose response was lost to a timeout already made the pipeline, and sending
 * it again makes a second one with the same name that nothing in the platform
 * can delete. Only three failures prove nothing was done:
 *
 * - the host never resolved or the connection was never made (cURL 6/7);
 * - the CONNECT timed out ("Connection timed out after …") — a read timeout is
 *   "Operation timed out after …", and that one may have landed;
 * - the platform said "not now" (429, 503) instead of answering.
 *
 * A class rather than a closure in the macro for the reason
 * `TransientHttpFailure` gives: `self::` inside an `Http::macro()` closure
 * resolves to PendingRequest.
 */
final class UnsentRequest
{
    private const REFUSED_STATUSES = [429, 503];

    private const NEVER_CONNECTED = '/cURL error (6|7):|Could not resolve host|Failed to connect|Connection timed out after/i';

    public static function matches(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return preg_match(self::NEVER_CONNECTED, $e->getMessage()) === 1;
        }

        return $e instanceof RequestException
            && in_array($e->response->status(), self::REFUSED_STATUSES, true);
    }
}
