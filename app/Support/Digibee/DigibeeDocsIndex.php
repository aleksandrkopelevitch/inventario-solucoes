<?php

namespace App\Support\Digibee;

/**
 * Parses Digibee's `llms.txt` — the index GitBook publishes beside the docs —
 * into the pages a sync should fetch.
 *
 * The format is one `## Section` heading per part of the corpus and one
 * `- [Title](https://…/page.md): description` bullet per page, with the
 * description optional. The file ends with an "Agent Instructions" chapter
 * that is prose rather than pages; it carries no `.md` bullets, so requiring
 * both the bullet shape AND a `.md` URL is all the guard that needs.
 *
 * **`llms-full.txt` is not the shortcut it looks like.** Measured 2026-09-02 it
 * is 702 KB against the corpus's ~4.9 MB, holds about 100 pages, and does NOT
 * contain the connector parameter tables — the one thing the flowSpec
 * generator is missing. The per-page `.md` fetch this index drives is the
 * real source.
 */
class DigibeeDocsIndex
{
    /** Real line breaks only — see ConnectorCardBuilder::LINE_BREAK for why `\R` is a trap here. */
    private const LINE_BREAK = '/\r\n|\r|\n/';

    private const ENTRY = '/^-\s+\[(?<title>[^\]]+)\]\((?<url>https?:\/\/[^)\s]+\.md)\)(?::\s*(?<description>.*))?$/mu';

    /**
     * @return list<DigibeeDocPage>
     */
    public static function parse(string $llmsTxt): array
    {
        $pages = [];
        $seen = [];
        $section = '';

        foreach (preg_split(self::LINE_BREAK, $llmsTxt) ?: [] as $line) {
            if (preg_match('/^##\s+(.+)$/u', $line, $heading) === 1) {
                $section = trim($heading[1]);

                continue;
            }

            if (preg_match(self::ENTRY, $line, $entry) !== 1) {
                continue;
            }

            $path = ltrim((string) parse_url($entry['url'], PHP_URL_PATH), '/');

            // The same page can be listed twice (a section's overview often is,
            // once under its own heading and once in a parent's list). The
            // first listing wins: it is the one whose section heading describes
            // where the page actually lives.
            if ($path === '' || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;

            $pages[] = new DigibeeDocPage(
                path: $path,
                url: $entry['url'],
                title: trim($entry['title']),
                description: trim($entry['description'] ?? ''),
                section: $section,
            );
        }

        return $pages;
    }
}
