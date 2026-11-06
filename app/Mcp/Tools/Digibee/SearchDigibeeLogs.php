<?php

namespace App\Mcp\Tools\Digibee;

/** `search_digibee_logs` on the iSol server — see DigibeeLogTool and LogsTools. */
class SearchDigibeeLogs extends DigibeeLogTool
{
    public function name(): string
    {
        return 'search_digibee_logs';
    }

    protected function action(): string
    {
        return 'report';
    }
}
