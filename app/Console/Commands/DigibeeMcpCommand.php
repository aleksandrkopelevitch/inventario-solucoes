<?php

namespace App\Console\Commands;

use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\Monitor\Mcp\LogsMcpServer;
use Illuminate\Console\Command;

/**
 * Runs the local Digibee logs MCP server on stdio, for a chat client on this
 * workstation. Claude Desktop on Windows starts it through WSL:
 *
 *   "command": "wsl.exe",
 *   "args": ["-d", "Ubuntu-24.04", "--cd", "/home/<user>/inventario-solucoes", "php", "artisan", "digibee:mcp"]
 *
 * See LogsMcpServer for why this is local and not a tool on the iSol server.
 */
class DigibeeMcpCommand extends Command
{
    protected $signature = 'digibee:mcp';

    protected $description = 'Serve Digibee execution logs to a local MCP client over stdio (workstation only)';

    public function handle(LogsMcpServer $server): int
    {
        if (app()->isProduction()) {
            fwrite(STDERR, DigibeeApiException::refusedMonitorOnServer()->getMessage() . "\n");

            return self::FAILURE;
        }

        // stdout carries the protocol and nothing else: a PHP warning printed
        // there is a corrupt frame, and the client drops the connection.
        ini_set('display_errors', 'stderr');

        $server->serve(STDIN, STDOUT);

        return self::SUCCESS;
    }
}
