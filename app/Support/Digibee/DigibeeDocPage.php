<?php

namespace App\Support\Digibee;

/**
 * One entry of Digibee's `llms.txt` index — the published page, the URL its
 * Markdown is served from, and where the index filed it.
 *
 * **`path` is the identity, never `title`.** The index legitimately repeats a
 * title (every month of the Release Notes is called "August"), while the path
 * is what the URL, the local file, the connector map and the caderno page all
 * key off. Measured on the live index 2026-09-02: 581 pages, 97 of them with
 * no description at all, and titles duplicated across sections.
 */
final readonly class DigibeeDocPage
{
    public function __construct(
        public string $path,        // documentation/connectors-and-triggers/connectors/web-protocols/rest-v2.md
        public string $url,
        public string $title,
        public string $description, // '' when the index gave none
        public string $section,     // the `## ` heading it was listed under
    ) {}

    /** The path without its `.md` — how a page is addressed everywhere but the filesystem. */
    public function key(): string
    {
        return preg_replace('/\.md$/', '', $this->path) ?? $this->path;
    }

    /**
     * The path segments BELOW `documentation/`, which is the tree the caderno
     * import reproduces. The leading segment is the same for every page and
     * would only add a root nobody asked for.
     *
     * @return list<string>
     */
    public function segments(): array
    {
        $segments = explode('/', $this->key());

        if (($segments[0] ?? null) === 'documentation') {
            array_shift($segments);
        }

        return array_values(array_filter($segments, fn (string $segment) => $segment !== ''));
    }

    /** The key of the page one level up, or null for a root. */
    public function parentKey(): ?string
    {
        $segments = $this->segments();
        array_pop($segments);

        return $segments === [] ? null : 'documentation/' . implode('/', $segments);
    }

    /** The file's own segment (`rest-v2`), which is what the connector map matches on. */
    public function slug(): string
    {
        $segments = $this->segments();

        return end($segments) ?: $this->key();
    }
}
