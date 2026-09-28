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
 * Four shapes carry a reference (a fourth, cross-space one, is described
 * further down). Two are the normalised ones
 * GitbookMarkdownNormalizer guarantees: `<img src="…">` inside a single-line
 * `<figure>`, and `{% file src="…" %}`. The third is a plain
 * `<a href="/files/{id}">` — GitBook renders a document LINKED from running
 * text or a table cell (not embedded as an image or a `{% file %}` block) this
 * way, and it is completely untouched by the normalizer, which only rewrites
 * image syntax. It is scoped to an `/files/` href specifically, never to
 * `<a href="https://…">` in general — an ordinary outbound link (a Jira
 * ticket, a Drive folder someone pasted) is not an embedded asset, and trying
 * to "re-host" every hyperlink in the corpus would be wrong, not thorough.
 * Found for real: a "Sprints" table in one imported space linked ~75 documents
 * this way, none logged as a failure, because nothing had even tried them —
 * `rehost()`'s regex simply had no case for an anchor at all.
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
 *   through the space's file list (`GitbookSource::files()`), which is the only
 *   place the real download URL exists.
 *
 * A `/files/{digits}` reference is left alone: that is one of ours, from a
 * previous import of the same page.
 *
 * A FOURTH shape: `/spaces/{otherSpaceId}/files/{id}` — a CROSS-SPACE
 * reference. GitBook uses the short `/files/{id}` form only within the space
 * that owns the file; a page that references an asset living in a DIFFERENT
 * space (copied/duplicated content, or a genuine cross-reference) spells out
 * the owning space's id. Resolving it needs THAT space's own file list, not
 * this one's — `GitbookAssetResolver` fetches and caches it lazily, per owning
 * space id, the first time one of its assets is actually referenced. Found for real:
 * two references in one page, both pointing at a different, already-imported
 * space. If the foreign space itself is inaccessible (wrong id, no access),
 * that is reported the same way as any other unfetchable asset — one page's
 * bad reference must never abort the whole import.
 *
 * The download is deliberately ours rather than Spatie's `addMediaFromUrl()`:
 * that helper has no size ceiling, and a documentation space can hold a 300MB
 * video someone dropped in once.
 *
 * Which reference means what is `GitbookAssetReference` and where its bytes are
 * is `GitbookAssetResolver`, both shared with `gitbook:archive` — an archive has
 * to download precisely what a restore will look for, and two copies of those
 * rules is how it silently stops doing that.
 */
class GitbookAssetImporter
{
    /** @var array<string, int> Source ref => media id, so one asset used twice is fetched once. */
    private array $seen = [];

    /**
     * `GitbookAssetResolver` holds the ref-to-URL rules AND the foreign-space
     * cache, so one instance per import run is what keeps that cache correct —
     * which is what the container gives, since this class is resolved per
     * `ImportGitbookSpace::handle()` call.
     */
    public function __construct(private readonly GitbookAssetResolver $resolver) {}

    /**
     * @param  array<string, array{url: string, name: string}>  $spaceFiles  From GitbookSource::files()
     */
    public function rehost(DocumentationPage $page, string $markdown, array $spaceFiles = []): GitbookAssetImport
    {
        $this->seen = [];
        $failed = [];
        $imported = 0;

        $rewritten = preg_replace_callback(
            GitbookAssetReference::PATTERN,
            function (array $m) use ($page, $spaceFiles, &$failed, &$imported): string {
                [$prefix, $ref, $suffix] = GitbookAssetReference::parts($m);

                $source = $this->resolver->resolve($ref, $spaceFiles);

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

        // GitBook falls back to showing the raw `/files/{id}` path AS the link's
        // visible text whenever no display name was set for it — found for real
        // in a "Sprints" table where every row read literally
        // `<a href="/files/{id}">/files/{id}</a>`. The href above already
        // resolves correctly (the link works), but leaving the label alone
        // would show the reader a foreign GitBook id as clickable text. Scoped
        // to an EXACT match against the href's own original value, so a real
        // display name (`>Checklist.pdf</a>`) is never touched — only text that
        // was already mirroring the path gets updated to mirror the new one.
        foreach ($this->seen as $oldRef => $mediaId) {
            if (Str::startsWith($oldRef, ['/files/', '/spaces/'])) {
                $rewritten = str_replace('>' . $oldRef . '</a>', '>/files/' . $mediaId . '</a>', $rewritten);
            }
        }

        return new GitbookAssetImport($rewritten, $imported, $failed);
    }

    /**
     * @param  string  $name  The asset's name as GitBook knows it; a CDN URL's
     *                        own basename is often signed or opaque.
     * @return int The new media's id.
     */
    private function fetch(DocumentationPage $page, string $url, string $name = ''): int
    {
        // A restore reads bytes off the disk, never the network: an archive's
        // file list points at the extracted copy inside it
        // (`GitbookArchive::files()`). Checked before the URL guard below,
        // because a filesystem path is not a URL and would fail it.
        if ($this->isLocalFile($url)) {
            return $this->store($page, $url, $this->fileName($name !== '' ? $name : $url, null), moving: false);
        }

        // The URLs come from an authenticated GitBook response, not from user
        // input, but this is still the app asking its own network for whatever
        // a URL says — the same guard the editor's "paste image URL" path uses.
        if (Validator::make(['url' => $url], ['url' => [new PublicUrl]])->fails()) {
            throw new \RuntimeException('URL aponta para um endereço interno ou não resolvível.');
        }

        $max = $this->ceiling();

        // A HEAD first, so a file already known to be too big is never fully
        // downloaded just to be rejected — the real cost this avoids: an
        // 11.53MB asset in the live corpus was downloaded in full and only
        // THEN turned away by Spatie's own (smaller) ceiling. Not every host
        // answers HEAD with a Content-Length (some CDNs 405/501 it, and
        // `throw: false` on the macro means a failed HEAD just returns a
        // failed response rather than throwing) — that case falls through to
        // the GET below, which still enforces the ceiling on the real size.
        $declared = (int) Http::gitbookAsset()->head($url)->header('Content-Length');

        if ($declared > $max) {
            throw new \RuntimeException('arquivo maior que o limite de ' . $max . ' bytes (anunciado: ' . $declared . ').');
        }

        // `gitbookAsset()`, not `gitbook()`: same timeouts and retry, but no
        // bearer token — the asset host is not GitBook's API host.
        $response = Http::gitbookAsset()->get($url);

        if ($response->failed()) {
            // GitBook's CDN answers a rejection (e.g. `.html` attachments —
            // "doesn't allow you to attach certain types of files", a
            // permanent policy, not a blip) with a short, readable body. Worth
            // surfacing over a bare status code: it's the difference between
            // "someone has to go curl this by hand to find out why" and
            // knowing immediately that this one will never succeed.
            $reason = str($response->body())->limit(200)->trim()->value();

            throw new \RuntimeException('download falhou (HTTP ' . $response->status() . ')'
                . ($reason !== '' ? ': ' . $reason : '.'));
        }

        $body = $response->body();

        if (strlen($body) > $max) {
            throw new \RuntimeException('arquivo maior que o limite de ' . $max . ' bytes.');
        }

        $path = tempnam(sys_get_temp_dir(), 'gitbook-');
        file_put_contents($path, $body);

        return $this->store($page, $path, $this->fileName($name ?: $url, $response->header('Content-Type')), moving: true);
    }

    /** A path that exists on this machine, as opposed to something to download. */
    private function isLocalFile(string $url): bool
    {
        return $url !== ''
            && ! Str::startsWith($url, ['http://', 'https://'])
            && str_starts_with($url, '/')
            && is_file($url);
    }

    /**
     * The media write both branches end at.
     *
     * `$moving` says who owns the file: a downloaded temp file is ours to lose
     * (Spatie MOVES what it is given), while a file inside an extracted archive
     * belongs to the archive and has to survive — the next page may reference
     * the same asset, and a restore that consumed its own backup as it went
     * could not be run twice.
     */
    private function store(DocumentationPage $page, string $path, string $name, bool $moving): int
    {
        try {
            $adder = $page->addMedia($path)->usingFileName($name);

            if (! $moving) {
                $adder->preservingOriginal();
            }

            return $adder->toMediaCollection(Documentable::DOCS_COLLECTION)->id;
        } finally {
            // addMedia() moves the file, but a failure mid-way would leave it.
            if ($moving && is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * The real byte ceiling: the smaller of our own config and Spatie's own
     * `media-library.max_file_size`. Without taking the minimum, a
     * `GITBOOK_MAX_ASSET_BYTES` above Spatie's ceiling promises a limit
     * `addMedia()` will simply refuse to honour.
     *
     * Both are 64MB today, and the reason they had to move TOGETHER is the
     * whole point of this method: Spatie's config was unpublished, so its 10MB
     * package default was the one actually clamping, and raising only the
     * GitBook setting would have changed nothing. `config/media-library.php`
     * now overrides that one key — see the note in that file.
     */
    private function ceiling(): int
    {
        $configured = (int) config('services.gitbook.max_asset_bytes');
        $spatie = (int) config('media-library.max_file_size');

        return $spatie > 0 ? min($configured, $spatie) : $configured;
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
