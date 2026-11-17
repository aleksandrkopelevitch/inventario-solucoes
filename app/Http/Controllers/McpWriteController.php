<?php

namespace App\Http\Controllers;

use App\Actions\Mcp\RevertMcpWrite;
use App\Actions\Mcp\RevertMcpWrites;
use App\Http\Requests\McpWriteIndexRequest;
use App\Http\Requests\RevertMcpWriteRequest;
use App\Http\Requests\RevertMcpWritesRequest;
use App\Models\McpWrite;
use App\View\Components\Mcp\WriteList;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Laravel\Passport\Client;

/**
 * "Alterações via MCP" — what chats changed in somebody's name, and the way
 * back. Admin only (`McpWritePolicy`). See docs-and-specs/mcp-write-tools.md §4.
 */
class McpWriteController extends Controller
{
    public function index(McpWriteIndexRequest $request): View|JsonResponse
    {
        $filters = $request->filters();

        if ($request->wantsJson()) {
            return response()->json(['updatableSlots' => [WriteList::slot($filters)]]);
        }

        return view('mcp.writes', [
            'filters' => $filters,
            // Only people and connectors that actually changed something: a
            // filter offering every account would be a list of dead ends.
            'people' => McpWrite::query()
                ->join('users', 'users.id', '=', 'mcp_writes.user_id')
                ->distinct()
                ->orderBy('users.name')
                ->pluck('users.name', 'users.id'),
            'clients' => Client::query()
                ->whereIn('id', McpWrite::query()->whereNotNull('oauth_client_id')->distinct()->select('oauth_client_id'))
                ->orderBy('name')
                ->pluck('name', 'id'),
        ]);
    }

    public function revert(RevertMcpWriteRequest $request, McpWrite $mcpWrite, RevertMcpWrite $revert): JsonResponse
    {
        $outcome = $revert->handle($mcpWrite, $request->user());

        return response()->json([
            'type'           => $outcome['reverted'] ? 'success' : 'warning',
            'message'        => $outcome['reverted'] ? 'Alteração revertida.' : 'Não revertida: ' . $outcome['reason'],
            'updatableSlots' => [WriteList::slot($request->filters())],
        ]);
    }

    public function revertSelection(RevertMcpWritesRequest $request, RevertMcpWrites $revert): JsonResponse
    {
        $filters = $request->filters();

        $result = $revert->handle(
            McpWrite::query()->filter($filters)->whereNull('reverted_at')->with('subject')->get(),
            $request->user(),
        );

        $skipped = count($result['skipped']);
        $message = $result['reverted'] . ' ' . ($result['reverted'] === 1 ? 'alteração revertida' : 'alterações revertidas') . '.';

        if ($skipped > 0) {
            $message .= ' ' . $skipped . ' ' . ($skipped === 1 ? 'pulada' : 'puladas') . ': '
                . collect($result['skipped'])->map(fn (array $skip) => $skip['reason'])->unique()->implode(' ');
        }

        return response()->json([
            'type'           => $skipped > 0 ? 'warning' : 'success',
            'message'        => $message,
            'updatableSlots' => [WriteList::slot($filters)],
        ]);
    }
}
