<?php

namespace App\Support\Digibee\Monitor\Mcp;

use App\Support\Digibee\Monitor\MonitorSession;

/**
 * Where the logs tools get their Digibee session, and what to tell the model
 * when there is none: the workstation reads the file its own sign-in wrote
 * (`WorkstationSession`), the iSol server reads the one an admin sent
 * (`ServerSession`), and only the instruction for getting a new one differs.
 */
interface SessionSource
{
    /** A usable session, or null — absent or expired. Re-read on every call. */
    public function session(): ?MonitorSession;

    /** The sentence that ends every failure to reach Digibee: who renews the session, and how. */
    public function renewal(): string;
}
