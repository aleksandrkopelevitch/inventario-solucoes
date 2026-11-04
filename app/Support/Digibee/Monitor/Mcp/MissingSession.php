<?php

namespace App\Support\Digibee\Monitor\Mcp;

/** No captured Monitor session, or an expired one — only a person can fix it, by signing in. */
final class MissingSession extends \RuntimeException {}
