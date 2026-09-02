<?php

namespace App\Support\Digibee;

/**
 * What one `digibee:docs:sync` run did. `changed` is the number worth reading:
 * the corpus is re-fetched whole (5 MB over a weekly schedule is not worth a
 * conditional-request protocol), so `fetched` says the sync ran and `changed`
 * says whether Digibee actually published anything.
 */
final readonly class DigibeeDocsSyncReport
{
    /**
     * @param  list<DigibeeDocPage>  $pages
     * @param  list<string>  $failures
     * @param  list<string>  $changedKeys
     */
    public function __construct(
        public array $pages = [],
        public int $fetched = 0,
        public int $changed = 0,
        public array $changedKeys = [],
        public array $failures = [],
        public bool $dryRun = false,
    ) {}

    public function total(): int
    {
        return count($this->pages);
    }
}
