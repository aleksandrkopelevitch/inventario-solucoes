<?php

namespace App\Mcp\Tools\Write;

/**
 * A write tool that CREATES a record by name — what `WriteTool::lockKey()`
 * locks on, so two simultaneous calls cannot both find the name free.
 */
interface CreatesRecords {}
