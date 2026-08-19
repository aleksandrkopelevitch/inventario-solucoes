<?php

namespace App\Support\Gitbook;

/**
 * Outcome of re-hosting one page's embedded assets: the rewritten Markdown,
 * how many files were brought over, and — named, never silent — the ones that
 * were left pointing at GitBook.
 */
class GitbookAssetImport
{
    /**
     * @param  array<int, string>  $failed  "url — reason", ready to print
     */
    public function __construct(
        public readonly string $markdown,
        public readonly int $imported = 0,
        public readonly array $failed = [],
    ) {}
}
