<?php

namespace App\Mcp;

use InvalidArgumentException;

/**
 * Arguments a tool cannot work with — answered as JSON-RPC `INVALID_PARAMS`.
 *
 * Distinct from `ToolResult::failure()`, and the line between them is who can
 * fix it. A missing required argument is the CLIENT's bug: it had the schema and
 * ignored it, and the model retrying with the same call would fail identically,
 * so it belongs in the protocol error the client surfaces. A slug that matches
 * nothing is the MODEL's question, answerable by asking a different one — that
 * goes back as a result the model reads.
 */
class InvalidToolArguments extends InvalidArgumentException {}
