<?php

namespace App\Support\Gitbook;

use App\Contracts\Documentable;
use App\Models\DocumentationPage;
use App\Rules\PublicUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls every image/file a page embeds into that page's own `docs` media
 * collection and repoints the Markdown at `/files/{id}`.
 *
 * Without this the import would "work" and quietly produce documentation made
 * of hotlinks: the pages read fine on day one and turn into broken images the
 * day the GitBook space is archived, the token is revoked, or a signed CDN URL
 * expires — which is precisely the outcome migrating off GitBook is meant to
 * avoid. `docs` is the collection App\Contracts\Documentable already defines
 * and MediaController/files.show already serves, so a re-hosted image is
 * indistinguishable from one uploaded through the editor.
 *
 * Two shapes carry a reference, and both are the normalised ones
 * GitbookMarkdownNormalizer guarantees: `<img src="…">` inside a single-line
 * `<figure>`, and `{% file src="…" %}`.
 *
 * Each of those carries one of two things, and the second one is a trap:
 *
 * - an absolute CDN URL, or
 * - **`/files/{gitbookFileId}`** — GitBook's own internal reference, which is
 *   the SAME path shape this app serves its own media on. Passing it through
 *   silently produces a page whose markup is flawless and whose every image is
 *   a 404 against our `files.show`, resolved with an id from another system.
 *   All 20 references in the first space imported for real were this shape, and
 *   the import cheerfully reported "0 assets re-hosted". They are resolved
 *   through the space's file list (`GitbookClient::files()`), which is the only
 *   place the real download URL exists.
 *
 * A `/files/{digits}` reference is left alone: that is one of ours, from a
 * previous import of the same page.
 *
 * The download is deliberately ours rather than Spatie's `addMediaFromUrl()`:
 * that helper has no size ceiling, and a documentation space can hold a 300MB
 * video someone dropped in once.
 */
class GitbookAssetImporter
{
    /** @var array<string, int> Source ref => media id, so one asset used twice is fetched once. */
    private array $seen = [];

    /**
     * @param  array<string, array{url: string, name: string}>  $spaceFiles  From GitbookClient::files()
     */
    public function rehost(DocumentationPage $page, string $markdown, array $spaceFiles = []): GitbookAssetImport
    {
        $this->seen = [];
        $failed = [];
        $imported = 0;

        $rewritten = preg_replace_callback(
            '/(<img[^>]*\ssrc=")([^"]+)(")|(\{%\s*file\s+src=")([^"]+)("\s*%\})/i',
            function (array $m) use ($page, $spaceFiles, &$failed, &$imported): string {
                $isImg = ($m[2] ?? '') !== '';
                [$prefix, $ref, $suffix] = $isImg
                    ? [$m[1], $m[2], $m[3]]
                    : [$m[4], $m[5], $m[6]];

                $ref = html_entity_decode($ref, ENT_QUOTES);
                $source = $this->source($ref, $spaceFiles);

                if ($source === null) {
                    // Already ours, or something we have no way to fetch.
                    return $prefix . $ref . $suffix;
                }

                if ($source['url'] === '') {
                    // A GitBook reference the space's file list doesn't contain.
                    // Named rather than left silently broken.
                    $failed[] = $ref . ' — não está na lista de arquivos do espaço.';

                    return $prefix . $ref . $suffix;
                }

                $mediaId = $this->seen[$ref] ?? null;

                if ($mediaId === null) {
                    try {
                        $mediaId = $this->fetch($page, $source['url'], $source['name']);
                        $imported++;
                    } catch (Throwable $e) {
                        $failed[] = $ref . ' — ' . $e->getMessage();

                        return $prefix . $ref . $suffix;
                    }
                    $this->seen[$ref] = $mediaId;
                }

                return $prefix . '/files/' . $mediaId . $suffix;
            },
            $markdown,
        ) ?? $markdown;

        return new GitbookAssetImport($rewritten, $imported, $failed);
    }

    /**
     * What to download for one reference, or null when there is nothing to do.
     * `['url' => '']` means "this IS a GitBook asset, but the space's file list
     * has no download URL for it" — a reportable miss, not a no-op.
     *
     * @param  array<string, array{url: string, name: string}>  $spaceFiles
     * @return array{url: string, name: string}|null
     */
    private function source(string $ref, array $spaceFiles): ?array
    {
        if (Str::startsWith($ref, ['http://', 'https://'])) {
            return ['url' => $ref, 'name' => ''];
        }

        if (preg_match('#^/files/([A-Za-z0-9_-]+)$#', $ref, $m)) {
            // Numeric: one of ours already (a previous import of this page).
            if (ctype_digit($m[1])) {
                return null;
            }

            return $spaceFiles[$m[1]] ?? ['url' => '', 'name' => ''];
        }

        // A repo-relative `.gitbook/assets/…` path or anything else we can't fetch.
        return null;
    }

    /**
     * @param  string  $name  The asset's name as GitBook knows it; a CDN URL's
     *                        own basename is often signed or opaque.
     * @return int The new media's id.
     */
    private function fetch(DocumentationPage $page, string $url, string $name = ''): int
    {
        // The URLs come from an authenticated GitBook response, not from user
        // input, but this is still the app asking its own network for whatever
        // a URL says — the same guard the editor's "paste image URL" path uses.
        if (Validator::make(['url' => $url], ['url' => [new PublicUrl]])->fails()) {
            throw new \RuntimeException('URL aponta para um endereço interno ou não resolvível.');
        }

        $max = (int) config('services.gitbook.max_asset_bytes');

        // `gitbookAsset()`, not `gitbook()`: same timeouts and retry, but no
        // bearer token — the asset host is not GitBook's API host.
        $response = Http::gitbookAsset()->get($url);

        if ($response->failed()) {
            throw new \RuntimeException('download falhou (HTTP ' . $response->status() . ').');
        }

        $body = $response->body();

        if (strlen($body) > $max) {
            throw new \RuntimeException('arquivo maior que o limite de ' . $max . ' bytes.');
        }

        $path = tempnam(sys_get_temp_dir(), 'gitbook-');
        file_put_contents($path, $body);

        try {
            return $page
                ->addMedia($path)
                ->usingFileName($this->fileName($name ?: $url, $response->header('Content-Type')))
                ->toMediaCollection(Documentable::DOCS_COLLECTION)
                ->id;
        } finally {
            // addMedia() moves the file, but a failure mid-way would leave it.
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * GitBook asset URLs carry the original name in the path, often
     * percent-encoded and followed by a signature query string.
     */
    private function fileName(string $url, ?string $contentType): string
    {
        $base = urldecode(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_BASENAME));
        $name = Str::of($base)->before('?')->trim()->value();

        if ($name === '' || ! Str::contains($name, '.')) {
            $extension = match (Str::before((string) $contentType, ';')) {
                'image/png'       => 'png',
                'image/gif'       => 'gif',
                'image/webp'      => 'webp',
                'image/svg+xml'   => 'svg',
                'image/jpeg'      => 'jpg',
                'application/pdf' => 'pdf',
                default           => 'bin',
            };
            $name = ($name ?: 'asset') . '.' . $extension;
        }

        return $name;
    }
}
