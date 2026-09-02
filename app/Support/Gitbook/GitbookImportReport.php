<?php

namespace App\Support\Gitbook;

use App\Models\Notebook;

/**
 * What one space's import did — the value the artisan command prints and the
 * one the tests assert against. `notebook` is null for a dry run (nothing was
 * written), which is also what makes "did this write anything?" answerable.
 */
class GitbookImportReport
{
    /**
     * @param  array<int, string>  $planned  Page titles, indented by depth, in the order they'd be written
     * @param  array<string, int>  $skipped  Dropped GitBook node types => count
     * @param  array<int, string>  $failures  Assets left pointing at GitBook, with the reason
     * @param  int  $sections  GitBook `group`s written as empty section pages
     * @param  int  $collapsed  Pages too deep to nest, whose ancestry went into their title
     * @param  int  $removed  Pages the caderno held that the space no longer has — only ever non-zero for a dated snapshot
     * @param  string|null  $notebookName  The caderno a DRY RUN would write to; `notebook` carries it on a real one
     */
    public function __construct(
        public readonly string $spaceId,
        public readonly string $spaceTitle,
        public readonly ?Notebook $notebook = null,
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $assets = 0,
        public readonly array $planned = [],
        public readonly array $skipped = [],
        public readonly array $failures = [],
        public readonly int $sections = 0,
        public readonly int $collapsed = 0,
        public readonly int $removed = 0,
        public readonly ?string $notebookName = null,
    ) {}

    public function pageCount(): int
    {
        return $this->notebook ? $this->created + $this->updated : count($this->planned);
    }
}
