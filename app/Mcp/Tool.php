<?php

namespace App\Mcp;

use App\Enums\AccessModule;

/**
 * One tool the MCP server exposes.
 *
 * A tool READS unless it says otherwise by implementing `Writes` — and only a
 * PERSON ever reaches a writing one. A minted MCP token is a static string in
 * somebody's configuration file, shared by whoever can read that file, and it
 * authenticates a program rather than a person: every mutation in this app is
 * authorized against a `User` and attributed to one, and a token is neither.
 * So a writing tool is shown only to an OAuth connection whose person is an
 * Editor of its module and authorized `mcp:write` (`Actor::canWrite()`), and
 * every write it makes is logged and revertible (`App\Models\McpWrite`). See
 * docs-and-specs/mcp-write-tools.md.
 */
interface Tool
{
    /** Snake_case, stable — an MCP client stores it in a conversation's history. */
    public function name(): string;

    /**
     * The app module this tool reads (App\Enums\AccessModule), or null for the
     * published knowledge base, which every account reads like `/docs`.
     *
     * Declared, not inferred: a person's connection is shown only the tools of
     * modules their level there is not None, so a new tool that forgets to say
     * which module it reads does not compile — the only version of this check
     * that survives the thirteenth tool (see App\Mcp\Actor).
     */
    public function module(): ?AccessModule;

    /** Human title for a client's tool picker. */
    public function title(): string;

    /**
     * What the tool does, addressed to the MODEL that will choose it.
     *
     * The single most load-bearing string in the file: a tool the model cannot
     * tell apart from its neighbour is a tool it calls at random. Say what the
     * tool answers, what it does NOT answer, and which tool does that instead.
     */
    public function description(): string;

    /** JSON Schema for `arguments`. @return array<string, mixed> */
    public function inputSchema(): array;

    /**
     * `$actor` is who is calling. The registry already hides a tool whose
     * module the caller cannot read; a tool needs the actor itself only when
     * its answer carries ANOTHER module's records (`get_solution` lists where
     * a relationship is documented, which is the documentation module's).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments, Actor $actor): ToolResult;
}
