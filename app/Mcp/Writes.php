<?php

namespace App\Mcp;

/**
 * A tool that CHANGES something — declared, never inferred.
 *
 * The marker is what the rest of the server keys on:
 *
 * - `ToolRegistry::all()` shows a writing tool only to a caller that
 *   `Actor::canWrite()` its module: a person, at level Editor there, whose
 *   connection was authorized with the `mcp:write` scope. A minted token never
 *   writes (see `Tool`), and neither does a Reader or a read-only connection.
 *   Hidden means unknown when called, exactly as for a read tool.
 * - `ToolRegistry::describe()` drops `readOnlyHint` for it. That annotation is
 *   what makes a client ask the person before each call, and the human reading
 *   that request is this feature's first safety net
 *   (docs-and-specs/mcp-write-tools.md §2.3).
 *
 * A writing tool's `module()` is never null: the published knowledge base is
 * the one surface every account reads, and there is nothing in it to write.
 */
interface Writes extends Tool
{
    /** True when calling it twice with the same arguments leaves the same state. */
    public function idempotent(): bool;

    /** True when it overwrites or removes what was there, as opposed to only adding. */
    public function destructive(): bool;
}
