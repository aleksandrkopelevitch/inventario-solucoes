<?php

namespace App\Mcp;

/**
 * A tool whose answer comes from outside this app's database — the Digibee
 * logs tools read the platform live. `ToolRegistry::describe()` reports it as
 * `openWorldHint: true`, the honest annotation for a read whose result the app
 * does not hold.
 */
interface ReachesOutside {}
