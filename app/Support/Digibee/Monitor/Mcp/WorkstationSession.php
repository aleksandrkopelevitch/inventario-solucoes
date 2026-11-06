<?php

namespace App\Support\Digibee\Monitor\Mcp;

use App\Support\Digibee\Monitor\MonitorSession;

/** The session `digibee:monitor:login` wrote on this workstation. */
final class WorkstationSession implements SessionSource
{
    public function session(): ?MonitorSession
    {
        $session = MonitorSession::fromFile((string) config('services.digibee.monitor.session_path'));

        return $session === null || $session->expired() ? null : $session;
    }

    public function renewal(): string
    {
        return LogsTools::LOGIN_HOWTO;
    }
}
