<?php

namespace App\Actions\Mcp;

use App\Models\McpWrite;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reverts a SELECTION of MCP writes — how a bad bulk edit (a chat that changed
 * 200 solutions) is undone in one action instead of 200 clicks.
 *
 * Newest first, so a field written twice ends at its OLDEST `before`: reverting
 * the second write puts the first one's `after` back, which is exactly what the
 * first write's own revert then expects to find. Each row is its own
 * transaction and its own verdict (`RevertMcpWrite`); one skipped — or failed —
 * row never blocks the rest, and every skip comes back with its reason.
 */
class RevertMcpWrites
{
    public function __construct(private readonly RevertMcpWrite $revert) {}

    /**
     * @param  Collection<int, McpWrite>  $writes
     * @return array{reverted: int, skipped: list<array{write: McpWrite, reason: string}>}
     */
    public function handle(Collection $writes, User $admin): array
    {
        $reverted = 0;
        $skipped = [];

        foreach ($writes->sortByDesc(fn (McpWrite $write) => $write->getKey()) as $write) {
            // One row failing in a way nobody foresaw must not abort the rest
            // halfway with half of them committed and no word about either:
            // it is reported (logged in full) and counted as skipped.
            try {
                $outcome = $this->revert->handle($write, $admin);
            } catch (Throwable $e) {
                report($e);
                $outcome = ['reverted' => false, 'reason' => 'erro inesperado ao reverter; veja o log.'];
            }

            if ($outcome['reverted']) {
                $reverted++;
            } else {
                $skipped[] = ['write' => $write, 'reason' => (string) $outcome['reason']];
            }
        }

        return ['reverted' => $reverted, 'skipped' => $skipped];
    }
}
