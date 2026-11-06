<?php

namespace App\Enums;

/** Where one remote Digibee sign-in stands (`App\Support\Digibee\Monitor\RemoteSignIn`). */
enum RemoteSignInStatus: string
{
    /** Queued, or the browser is still launching — nothing to show yet. */
    case Starting = 'starting';

    /** The browser is up and the screen streams it: somebody signs in. */
    case Waiting = 'waiting';

    case Captured = 'captured';

    case Failed = 'failed';

    case Cancelled = 'cancelled';

    /** A run that still holds (or is about to hold) the server's browser. */
    public function active(): bool
    {
        return $this === self::Starting || $this === self::Waiting;
    }
}
