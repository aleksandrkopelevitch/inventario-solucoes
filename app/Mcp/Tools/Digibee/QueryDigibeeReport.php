<?php

namespace App\Mcp\Tools\Digibee;

/** `query_digibee_report` on the iSol server — see DigibeeLogTool and LogsTools. */
class QueryDigibeeReport extends DigibeeLogTool
{
    public function name(): string
    {
        return 'query_digibee_report';
    }

    protected function action(): string
    {
        return 'query';
    }
}
