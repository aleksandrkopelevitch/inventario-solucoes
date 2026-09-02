<?php

namespace App\Support\Digibee;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The local mirror of Digibee's published documentation: plain Markdown files
 * under `storage/app/private/digibee-docs/`, laid out exactly like the URLs
 * they came from, plus a `manifest.json` naming every page and hashing its
 * content.
 *
 * **Files, not a table, and not a vector index.** The retrieval this corpus
 * exists for is a SYMBOL lookup — "what does `rest-connector-v2` take as
 * params" — and the symbol is known exactly on both sides
 * (App\Support\Digibee\ConnectorDocMap). An approximate index would be strictly
 * worse at the one question that matters, and it would cost the property the
 * flowSpec generator was deliberately built around: the same request producing
 * the same context, testably. What genuinely IS fuzzy ("how do I do X in
 * Digibee") already has a hosted answer — GitBook serves
 * `GET <page>.md?ask=<question>` over this same corpus, always current — so
 * there is nothing here worth re-implementing with embeddings.
 *
 * The manifest hashes CONTENT rather than trusting a timestamp, for the same
 * reason `DocumentationSearchService` does: it is what lets a sync say which
 * pages actually changed, and what lets the connector cards be rebuilt only
 * when their page did.
 */
class DigibeeDocsCorpus
{
    /** Bump when the manifest's shape changes, so a stale one is re-synced rather than misread. */
    public const VERSION = 1;

    public const DIRECTORY = 'digibee-docs';

    public const MANIFEST = self::DIRECTORY . '/manifest.json';

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public function isSynced(): bool
    {
        return $this->manifest() !== null;
    }

    /** @return array{version: int, synced_at: string, index_url: string, pages: list<array<string, mixed>>}|null */
    public function manifest(): ?array
    {
        $raw = $this->disk()->get(self::MANIFEST);

        if ($raw === null) {
            return null;
        }

        $manifest = json_decode($raw, true);

        return is_array($manifest) && ($manifest['version'] ?? null) === self::VERSION ? $manifest : null;
    }

    public function syncedAt(): ?CarbonImmutable
    {
        $at = $this->manifest()['synced_at'] ?? null;

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }

    /**
     * Every page the last sync wrote, in index order.
     *
     * @return list<DigibeeDocPage>
     */
    public function pages(): array
    {
        return array_map(
            fn (array $entry) => new DigibeeDocPage(
                path: (string) $entry['path'],
                url: (string) $entry['url'],
                title: (string) $entry['title'],
                description: (string) ($entry['description'] ?? ''),
                section: (string) ($entry['section'] ?? ''),
            ),
            $this->manifest()['pages'] ?? [],
        );
    }

    /** The page filed under this key (`documentation/…/rest-v2`, no extension). */
    public function page(string $key): ?DigibeeDocPage
    {
        foreach ($this->pages() as $page) {
            if ($page->key() === $key) {
                return $page;
            }
        }

        return null;
    }

    public function markdown(string $key): ?string
    {
        return $this->disk()->get(self::DIRECTORY . '/' . trim($key, '/') . '.md');
    }

    /**
     * Writes a page and reports whether its content actually changed — which is
     * what a sync counts and what decides a card rebuild.
     */
    public function put(DigibeeDocPage $page, string $markdown): bool
    {
        $path = self::DIRECTORY . '/' . $page->path;
        $changed = $this->disk()->get($path) !== $markdown;

        $this->disk()->put($path, $markdown);

        return $changed;
    }

    /** @param list<DigibeeDocPage> $pages */
    public function writeManifest(array $pages, string $indexUrl): void
    {
        $this->disk()->put(self::MANIFEST, json_encode([
            'version'   => self::VERSION,
            'synced_at' => now()->toIso8601String(),
            'index_url' => $indexUrl,
            'pages'     => array_map(fn (DigibeeDocPage $page) => [
                'path'        => $page->path,
                'url'         => $page->url,
                'title'       => $page->title,
                'description' => $page->description,
                'section'     => $page->section,
                'sha'         => hash('sha256', (string) $this->markdown($page->key())),
            ], $pages),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
