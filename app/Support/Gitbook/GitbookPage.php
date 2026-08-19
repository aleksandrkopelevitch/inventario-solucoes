<?php

namespace App\Support\Gitbook;

/**
 * One GitBook page, flattened out of the space's tree and ready to become a
 * DocumentationPage. `title` is already the final title (breadcrumb-prefixed
 * or not, see GitbookPageTree); `trail` keeps the original ancestry so the
 * import can report where a page came from.
 */
class GitbookPage
{
    /**
     * @param  array<int, string>  $trail  Ancestor titles, outermost first.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $path,
        public readonly array $trail = [],
    ) {}

    /** "Getting started › Instalação" — what the page looked like in GitBook. */
    public function origin(): string
    {
        return implode(' › ', [...$this->trail, $this->title]);
    }
}
