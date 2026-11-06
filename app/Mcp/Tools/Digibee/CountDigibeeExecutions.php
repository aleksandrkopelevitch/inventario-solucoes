<?php

namespace App\Mcp\Tools\Digibee;

/** `count_digibee_executions` on the iSol server — see DigibeeLogTool and LogsTools. */
class CountDigibeeExecutions extends DigibeeLogTool
{
    public function name(): string
    {
        return 'count_digibee_executions';
    }

    protected function action(): string
    {
        return 'count';
    }
}
