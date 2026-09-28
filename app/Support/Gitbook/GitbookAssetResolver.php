<?php

namespace App\Support\Gitbook;

use App\Contracts\GitbookSource;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns one asset reference into something fetchable, or says there is nothing
 * to fetch.
 *
 * Lifted out of `GitbookAssetImporter` so `gitbook:archive` resolves references
 * exactly the way a restore will look them up. The archive's whole promise is
 * that it holds the bytes for every reference the import would have chased, and
 * two copies of this logic is how that promise stops being true — the archive
 * would be complete against the rules as they were on the day it was written.
 *
 * One instance per import/archive run: the foreign-space cache below is only
 * correct for the length of one.
 */
class GitbookAssetResolver
{
    /**
     * @var array<string, array<string, array{url: string, name: string}>>
     *                                                                     Foreign space id => its file list, fetched once and reused for the
     *                                                                     rest of this run.
     */
    private array $foreignSpaceFiles = [];

    public function __construct(private readonly GitbookSource $source) {}

    /**
     * What to fetch for one reference, or null when there is nothing to do.
     *
     * `['url' => '']` means "this IS a GitBook asset, but the space's file list
     * has no download URL for it" — a reportable miss, deliberately different
     * from null, which is a no-op.
     *
     * @param  array<string, array{url: string, name: string}>  $spaceFiles
     * @return array{url: string, name: string}|null
     */
    public function resolve(string $reference, array $spaceFiles): ?array
    {
        // The FULL reference as a key comes first, and it is what an archive
        // uses: `GitbookArchive::files()` keys absolute URLs and cross-space
        // references by the reference itself, so a restore resolves offline
        // something that originally needed a second API call or a CDN. A live
        // space's file list is keyed by bare file ids only, so this never fires
        // against the API — it is inert on the path it does not serve.
        if (isset($spaceFiles[$reference])) {
            return $spaceFiles[$reference];
        }

        if (Str::startsWith($reference, ['http://', 'https://'])) {
            return ['url' => $reference, 'name' => ''];
        }

        if (preg_match('#^/files/([A-Za-z0-9_-]+)$#', $reference, $m)) {
            // Numeric: one of ours already (a previous import of this page).
            if (ctype_digit($m[1])) {
                return null;
            }

            return $spaceFiles[$m[1]] ?? ['url' => '', 'name' => ''];
        }

        if (preg_match('#^/spaces/([A-Za-z0-9]+)/files/([A-Za-z0-9_-]+)$#', $reference, $m)) {
            return $this->foreignFile($m[1], $m[2]);
        }

        // A repo-relative `.gitbook/assets/…` path or anything else we cannot fetch.
        return null;
    }

    /**
     * One asset living in ANOTHER space's file list. GitBook uses the short
     * `/files/{id}` form only inside the space that owns the file; a page
     * pointing at an asset in a different space spells that space's id out, and
     * resolving it needs THAT space's list.
     *
     * @return array{url: string, name: string}
     */
    private function foreignFile(string $foreignSpaceId, string $fileId): array
    {
        if (! isset($this->foreignSpaceFiles[$foreignSpaceId])) {
            try {
                $this->foreignSpaceFiles[$foreignSpaceId] = $this->source->files($foreignSpaceId);
            } catch (Throwable) {
                // Wrong id, no access, or the space is gone — the reference is
                // simply unresolvable, not a reason to abort the run.
                $this->foreignSpaceFiles[$foreignSpaceId] = [];
            }
        }

        return $this->foreignSpaceFiles[$foreignSpaceId][$fileId] ?? ['url' => '', 'name' => ''];
    }
}
