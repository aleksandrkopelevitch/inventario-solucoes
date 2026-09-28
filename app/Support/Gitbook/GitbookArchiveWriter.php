<?php

namespace App\Support\Gitbook;

use App\Rules\PublicUrl;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Writes ONE space into a self-contained `.zip` — the backup half of
 * `GitbookArchive`.
 *
 * It stores GitBook's own answers verbatim (see `GitbookArchiveFormat`), with a
 * single deliberate exception: `assets/index.json` is RESOLVED here, while the
 * network is still there. A reference can point at another space's file list or
 * at an absolute CDN URL, and both need a request to turn into bytes — doing
 * that at restore time would mean an archive that only restores while the thing
 * it is backing up still exists, which is not a backup.
 *
 * An asset that cannot be fetched does not fail the space. It is recorded in
 * the manifest's `asset_failures`, printed by the command and left out of the
 * index — so the archive says what it is missing instead of looking complete.
 * The same reasoning as the import's own failure list: one bad reference in a
 * 150-page space must not cost the other 149.
 */
class GitbookArchiveWriter
{
    /** @var array<int, string> */
    private array $failures = [];

    /**
     * @var array<string, array{file: string, name: string, id: string, path: string}>
     *                                                                                 Reference => where it goes in the zip, and the temp file holding its
     *                                                                                 bytes. The BYTES are on disk rather than in this array on purpose:
     *                                                                                 the per-asset ceiling is 64MB and a space can hold hundreds of
     *                                                                                 assets, so a backup that buffered them all would run the box out of
     *                                                                                 memory on exactly the corpus it exists for.
     */
    private array $assets = [];

    /**
     * @var array<string, true> Every reference already dealt with, however it
     *                          went. Keyed on ATTEMPT rather than on success:
     *                          the unreferenced-files sweep re-visits anything
     *                          the page walk touched, so recording only what
     *                          worked meant a failing asset was downloaded
     *                          twice and reported twice.
     */
    private array $attempted = [];

    private int $bytes = 0;

    private ?string $spool = null;

    public function __construct(
        private readonly GitbookClient $client,
        private readonly GitbookAssetResolver $resolver,
    ) {}

    /**
     * @param  Closure(string): void|null  $progress  Called with a short line per step.
     * @return array<string, mixed> the manifest that was written
     */
    public function write(string $spaceId, string $destination, ?Closure $progress = null): array
    {
        $report = fn (string $line) => $progress && $progress($line);

        $space = $this->client->space($spaceId);
        $title = trim((string) ($space['title'] ?? '')) ?: 'GitBook ' . $spaceId;

        $report('lendo a árvore de páginas');
        $tree = $this->client->pageTree($spaceId);

        // The space's own file list, for the `/files/{id}` references that make
        // up most of a corpus. Cross-space and absolute references are resolved
        // per reference below, through the same resolver the import uses.
        $spaceFiles = $this->client->files($spaceId);

        // Every `document` node, flattened — the archive keys Markdown by node
        // id, so the shape in `pages.json` is what reconstructs the tree and
        // this walk only needs to know what to fetch.
        $pageIds = $this->documentIds($tree);

        $this->failures = [];
        $this->assets = [];
        $this->attempted = [];
        $this->bytes = 0;

        $markdown = [];

        foreach ($pageIds as $i => $pageId) {
            $report('página ' . ($i + 1) . '/' . count($pageIds));

            try {
                $body = $this->client->pageMarkdown($spaceId, $pageId);
            } catch (Throwable $e) {
                // A page that cannot be read is named and skipped: the rest of
                // the space is still worth archiving, and a silent gap is the
                // one thing a backup must not have.
                $this->failures[] = 'página ' . $pageId . ' — ' . $e->getMessage();

                continue;
            }

            $markdown[$pageId] = $body;

            foreach (GitbookAssetReference::all($body) as $reference) {
                $this->collect($reference, $spaceFiles);
            }
        }

        $referenced = count($this->assets);

        // Then EVERY file the space holds, referenced or not.
        //
        // The import only ever chases what a page embeds, and that is right for
        // an import — but a backup that keeps only what today's parser
        // recognises is a backup shaped by today's parser. Found on the first
        // real archive: a `{% openapi %}` block, which the normalizer
        // deliberately down-converts to a callout naming the file rather than
        // rendering it, so its spec was referenced in prose and matched no
        // asset pattern. The bytes were about to be lost for the one reason
        // this command exists — "the original is still in GitBook" stops being
        // true the day it is archived.
        //
        // Cheap, too: these are files already uploaded to the space, and
        // `collect()` skips anything the walk above took.
        $report('anexos não referenciados');

        foreach (array_keys($spaceFiles) as $fileId) {
            $this->collect('/files/' . $fileId, $spaceFiles);
        }

        $manifest = [
            'format'            => GitbookArchiveFormat::VERSION,
            'space_id'          => $spaceId,
            'space_title'       => $title,
            'exported_at'       => now()->toIso8601String(),
            'pages'             => count($markdown),
            'assets'            => count($this->assets),
            'assets_referenced' => $referenced,
            'asset_bytes'       => $this->bytes,
            'asset_failures'    => $this->failures,
        ];

        $report('gravando ' . basename($destination));
        $this->zip($destination, $space, $tree, $markdown, $manifest);

        return $manifest;
    }

    /** @return array<int, string> Every reference this archive could not fetch. */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Resolves one reference and downloads it, once.
     *
     * @param  array<string, array{url: string, name: string}>  $spaceFiles
     */
    private function collect(string $reference, array $spaceFiles): void
    {
        if (isset($this->attempted[$reference])) {
            return;
        }

        $this->attempted[$reference] = true;

        $source = $this->resolver->resolve($reference, $spaceFiles);

        if ($source === null) {
            // Already ours, or a repo-relative path there is nothing to fetch
            // for. Not a failure — the import treats it the same way.
            return;
        }

        if ($source['url'] === '') {
            $this->failures[] = $reference . ' — não está na lista de arquivos do espaço.';

            return;
        }

        try {
            $path = $this->download($source['url']);
        } catch (Throwable $e) {
            $this->failures[] = $reference . ' — ' . $e->getMessage();

            return;
        }

        // The bare file id too, so a restore resolves the short `/files/{id}`
        // form without re-deriving it from the reference.
        $id = preg_match('#/files/([A-Za-z0-9_-]+)$#', $reference, $m) ? $m[1] : '';

        $this->assets[$reference] = [
            'file' => GitbookArchiveFormat::assetPath($reference, $source['name']),
            'name' => $source['name'],
            'id'   => $id,
            'path' => $path,
            // Recorded so the archive can be CHECKED rather than trusted: a
            // restore writes these bytes into media, and years later "is this
            // the file that was archived?" should be answerable without the
            // source. It also makes a re-archive able to say what changed —
            // worth having, because GitBook's CDN serves a transformed variant
            // (`Vary: accept`) and the same asset legitimately comes back with
            // different bytes once it has been optimised on their side.
            'sha256' => hash_file('sha256', $path),
            'bytes'  => (int) filesize($path),
        ];

        $this->bytes += (int) filesize($path);
    }

    /**
     * Downloads one asset to a temp file and returns its path.
     *
     * The ceiling is `GitbookAssetImporter`'s, deliberately: an archive holding
     * something a restore would refuse is an archive that lies about what it can
     * put back. Anything over it is reported rather than silently dropped.
     */
    private function download(string $url): string
    {
        if (Validator::make(['url' => $url], ['url' => [new PublicUrl]])->fails()) {
            throw new RuntimeException('URL aponta para um endereço interno ou não resolvível.');
        }

        $max = (int) config('services.gitbook.max_asset_bytes');

        // A HEAD first, so something already known to be too big is never
        // downloaded in full just to be turned away. Not every host answers
        // HEAD with a Content-Length, so the real check is on the body below.
        $declared = (int) Http::gitbookAsset()->head($url)->header('Content-Length');

        if ($max > 0 && $declared > $max) {
            throw new RuntimeException('arquivo maior que o limite de ' . $max . ' bytes (anunciado: ' . $declared . ').');
        }

        // `gitbookAsset()`, not `gitbook()`: same timeouts and retry, no bearer
        // token — the asset host is not GitBook's API host.
        $response = Http::gitbookAsset()->get($url);

        if ($response->failed()) {
            $reason = str($response->body())->limit(200)->trim()->value();

            throw new RuntimeException('download falhou (HTTP ' . $response->status() . ')'
                . ($reason !== '' ? ': ' . $reason : '.'));
        }

        $body = $response->body();

        if ($max > 0 && strlen($body) > $max) {
            throw new RuntimeException('arquivo maior que o limite de ' . $max . ' bytes.');
        }

        $path = $this->spool() . '/' . bin2hex(random_bytes(12));
        file_put_contents($path, $body);

        return $path;
    }

    /** Temp directory holding this run's downloads, removed once the zip is closed. */
    private function spool(): string
    {
        if ($this->spool === null) {
            $this->spool = rtrim(sys_get_temp_dir(), '/') . '/gitbook-archive-spool-' . bin2hex(random_bytes(8));

            if (! mkdir($this->spool, 0700, true) && ! is_dir($this->spool)) {
                throw new RuntimeException('Não consegui criar o diretório temporário de download.');
            }
        }

        return $this->spool;
    }

    private function clearSpool(): void
    {
        foreach ($this->assets as $asset) {
            @unlink($asset['path']);
        }

        if ($this->spool !== null) {
            @rmdir($this->spool);
            $this->spool = null;
        }
    }

    /**
     * @param  array<string, mixed>  $space
     * @param  array<int, array<string, mixed>>  $tree
     * @param  array<string, string>  $markdown
     * @param  array<string, mixed>  $manifest
     */
    private function zip(string $destination, array $space, array $tree, array $markdown, array $manifest): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não consegui criar o diretório ' . $directory . '.');
        }

        $zip = new ZipArchive;

        // Written to a neighbouring temp name and renamed at the end, so an
        // interrupted run never leaves something that looks like a finished
        // backup. A half-written archive is exactly the file somebody would
        // later trust.
        $temporary = $destination . '.part';

        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não consegui criar o arquivo ' . $temporary . '.');
        }

        $zip->addFromString(GitbookArchiveFormat::MANIFEST, $this->encode($manifest));
        $zip->addFromString(GitbookArchiveFormat::SPACE, $this->encode($space));
        $zip->addFromString(GitbookArchiveFormat::PAGES, $this->encode($tree));

        foreach ($markdown as $pageId => $body) {
            $zip->addFromString(GitbookArchiveFormat::pagePath((string) $pageId), $body);
        }

        $index = [];

        foreach ($this->assets as $reference => $asset) {
            $zip->addFile($asset['path'], $asset['file']);
            $index[$reference] = [
                'file'   => $asset['file'],
                'name'   => $asset['name'],
                'id'     => $asset['id'],
                'bytes'  => $asset['bytes'],
                'sha256' => $asset['sha256'],
            ];
        }

        // `JSON_FORCE_OBJECT`, because a space with no assets at all would
        // otherwise write `[]` — PHP encodes an empty array as a list. It reads
        // back fine here (an empty foreach either way), but the format is meant
        // to outlive this app and be readable by something else, and a file
        // documented as an object must not sometimes be a list.
        $zip->addFromString(
            GitbookArchiveFormat::ASSET_INDEX,
            (string) json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
        );

        // `addFile()` only READS at close() — the spool has to still be there.
        $zip->close();
        $this->clearSpool();

        if (! rename($temporary, $destination)) {
            @unlink($temporary);

            throw new RuntimeException('Não consegui finalizar o arquivo ' . $destination . '.');
        }
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Every `document` node id in the tree, in reading order.
     *
     * Only documents: a `group` has no content to fetch, and `link`/`computed`
     * are the two the import drops on purpose (`GitbookPageTree`). The whole
     * tree is stored verbatim regardless, so `pages.json` still describes the
     * space's real shape — this only decides what Markdown to ask for.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, string>
     */
    private function documentIds(array $nodes): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? 'document') === 'document' && filled($node['id'] ?? null)) {
                $ids[] = (string) $node['id'];
            }

            if (filled($node['pages'] ?? null) && is_array($node['pages'])) {
                $ids = [...$ids, ...$this->documentIds($node['pages'])];
            }
        }

        return $ids;
    }
}
