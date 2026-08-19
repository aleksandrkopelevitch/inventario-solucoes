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
 * Two shapes carry a URL, and both are the normalised ones
 * GitbookMarkdownNormalizer guarantees: `<img src="…">` inside a single-line
 * `<figure>`, and `{% file src="…" %}`.
 *
 * The download is deliberately ours rather than Spatie's `addMediaFromUrl()`:
 * that helper has no size ceiling, and a documentation space can hold a 300MB
 * video someone dropped in once.
 */
class GitbookAssetImporter
{
    /** @var array<string, int> URL => media id, so one asset used twice is fetched once. */
    private array $seen = [];

    public function rehost(DocumentationPage $page, string $markdown): GitbookAssetImport
    {
        $this->seen = [];
        $failed = [];
        $imported = 0;

        $rewritten = preg_replace_callback(
            '/(<img[^>]*\ssrc=")([^"]+)(")|(\{%\s*file\s+src=")([^"]+)("\s*%\})/i',
            function (array $m) use ($page, &$failed, &$imported): string {
                $isImg = ($m[2] ?? '') !== '';
                [$prefix, $url, $suffix] = $isImg
                    ? [$m[1], $m[2], $m[3]]
                    : [$m[4], $m[5], $m[6]];

                $url = html_entity_decode($url, ENT_QUOTES);

                // Anything not an absolute http(s) URL is already local (a
                // previous import's /files/{id}) or unfetchable (a repo-relative
                // .gitbook/assets path); either way, leave it exactly as it is.
                if (! Str::startsWith($url, ['http://', 'https://'])) {
                    return $prefix . $url . $suffix;
                }

                $mediaId = $this->seen[$url] ?? null;

                if ($mediaId === null) {
                    try {
                        $mediaId = $this->fetch($page, $url);
                        $imported++;
                    } catch (Throwable $e) {
                        $failed[] = $url . ' — ' . $e->getMessage();

                        return $prefix . $url . $suffix;
                    }
                    $this->seen[$url] = $mediaId;
                }

                return $prefix . '/files/' . $mediaId . $suffix;
            },
            $markdown,
        ) ?? $markdown;

        return new GitbookAssetImport($rewritten, $imported, $failed);
    }

    /** @return int The new media's id. */
    private function fetch(DocumentationPage $page, string $url): int
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
                ->usingFileName($this->fileName($url, $response->header('Content-Type')))
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
