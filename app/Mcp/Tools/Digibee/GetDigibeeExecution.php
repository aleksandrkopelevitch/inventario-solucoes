<?php

namespace App\Mcp\Tools\Digibee;

/** `get_digibee_execution` on the iSol server — see DigibeeLogTool and LogsTools. */
class GetDigibeeExecution extends DigibeeLogTool
{
    public function name(): string
    {
        return 'get_digibee_execution';
    }

    protected function action(): string
    {
        return 'execution';
    }
}
