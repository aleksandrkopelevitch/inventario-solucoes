<?php

namespace App\Services\Documentation;

use Illuminate\Support\Collection;

/**
 * Result of `ContextPageResolver::resolve()`: other documentation pages a
 * Documentation Assistant turn was given as reference, already masked and
 * truncated, plus the ones that did not fit — surfaced in the message's `meta`
 * for audit, exactly as `ContextDocumentSet` does for uploaded documents.
 */
final class ContextPageSet
{
    /**
     * @param  Collection<int, array{title: string, notebook: string, content: string, truncated: bool}>  $pages
     * @param  list<string>  $omitted  titles left out (over the cap, or past the budget)
     */
    public function __construct(
        public readonly Collection $pages,
        public readonly array $omitted,
    ) {}

    /** @return list<string> the titles that DID reach the prompt, for `meta` */
    public function names(): array
    {
        return $this->pages->pluck('title')->all();
    }
}
