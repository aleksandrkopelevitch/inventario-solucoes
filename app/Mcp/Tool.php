<?php

namespace App\Mcp;

/**
 * One tool the MCP server exposes.
 *
 * Every implementation is READ-ONLY, and that is a property of the whole
 * feature rather than of any one class: an MCP token is a static string sitting
 * in somebody's configuration file, shared by whoever can read that file, and
 * it authenticates a program rather than a person. Nothing the catalog records
 * about who changed what would survive a write arriving through it — every
 * mutation in this app is authorized against a `User` and attributed to one, and
 * a token is neither. `readOnly()` is declared rather than assumed so the
 * annotation reaches the client and a future writing tool has to say so out loud.
 */
interface Tool
{
    /** Snake_case, stable — an MCP client stores it in a conversation's history. */
    public function name(): string;

    /**
     * Whether this tool reads the INVENTORY — the catalog, the diagrams, the
     * people and their contacts. Everything the knowledge base is not.
     *
     * Declared rather than inferred, and declared on the narrow side: a
     * connection made by a `Reader` (the tier Entra SSO provisions, which in the
     * browser reaches `/docs` and nothing else) is shown only the tools that
     * answer `false` here. A new tool that forgets to say is a new tool that
     * does not compile, which is the only version of this check that survives
     * somebody adding the thirteenth one (see App\Mcp\Actor).
     */
    public function requiresInventory(): bool;

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

    /** @param array<string, mixed> $arguments */
    public function handle(array $arguments): ToolResult;
}
