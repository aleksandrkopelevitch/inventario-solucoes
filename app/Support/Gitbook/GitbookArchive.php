<?php

namespace App\Support\Gitbook;

use App\Contracts\GitbookSource;
use RuntimeException;
use ZipArchive;

/**
 * A `.zip` written by `gitbook:archive`, read as if it were the live API.
 *
 * It implements `GitbookSource`, so `ImportGitbookSpace` restores from it
 * without knowing it is not talking to GitBook — which is the point: a restore
 * is not a second import with its own bugs, it is THE import with its source
 * swapped. Nothing here reaches the network.
 *
 * The zip is extracted once, to a temporary directory the instance owns and
 * deletes (`close()`, and a destructor for the paths that never get there). It
 * is extracted rather than read entry-by-entry because the assets have to
 * become real paths on disk: `GitbookAssetImporter` hands a path to Spatie, and
 * `preservingOriginal()` is what stops it consuming the archive's own copy — an
 * asset referenced by two pages is added twice.
 */
class GitbookArchive implements GitbookSource
{
    private ?string $root = null;

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    private function __construct(private readonly string $path) {}

    /**
     * @throws RuntimeException when the file is not a readable archive of a
     *                          format this version understands
     */
    public static function open(string $path): self
    {
        $archive = new self($path);
        $archive->extract();

        return $archive;
    }

    public function spaceId(): string
    {
        return (string) ($this->manifest()['space_id'] ?? '');
    }

    public function spaceTitle(): string
    {
        return (string) ($this->manifest()['space_title'] ?? '');
    }

    /** ISO-8601, as written. */
    public function exportedAt(): string
    {
        return (string) ($this->manifest()['exported_at'] ?? '');
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest ??= $this->json(GitbookArchiveFormat::MANIFEST);
    }

    public function space(string $spaceId): array
    {
        return $this->json(GitbookArchiveFormat::SPACE);
    }

    /** @return array<int, array<string, mixed>> */
    public function pageTree(string $spaceId): array
    {
        return $this->json(GitbookArchiveFormat::PAGES);
    }

    public function pageMarkdown(string $spaceId, string $pageId): string
    {
        $path = $this->root() . '/' . GitbookArchiveFormat::pagePath($pageId);

        // A page with no Markdown file is a page that had no content when it
        // was archived, which is a normal state (a GitBook `group`) — not a
        // damaged archive.
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Every asset the archive holds, keyed BOTH by its bare GitBook file id and
     * by the full reference as it appears in the Markdown, with `url` pointing
     * at the extracted copy on disk.
     *
     * The double keying is what makes a restore offline-complete:
     * `GitbookAssetResolver` looks the full reference up first, so an absolute
     * CDN URL and a `/spaces/{other}/files/{id}` cross-space reference — both of
     * which needed the network when the archive was written — resolve to a local
     * file now. The `$spaceId` is ignored for the same reason: a cross-space
     * lookup asks this archive for a foreign space's list, and the honest answer
     * is "here is everything I hold".
     *
     * @return array<string, array{url: string, name: string}>
     */
    public function files(string $spaceId): array
    {
        $files = [];

        foreach ($this->json(GitbookArchiveFormat::ASSET_INDEX) as $reference => $entry) {
            $path = $this->root() . '/' . ($entry['file'] ?? '');

            if (! is_file($path)) {
                continue;
            }

            $files[(string) $reference] = ['url' => $path, 'name' => (string) ($entry['name'] ?? '')];

            if (filled($entry['id'] ?? null)) {
                $files[(string) $entry['id']] = $files[(string) $reference];
            }
        }

        return $files;
    }

    /** Removes the extracted copy. Safe to call twice. */
    public function close(): void
    {
        if ($this->root === null) {
            return;
        }

        $this->deleteTree($this->root);
        $this->root = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function root(): string
    {
        return $this->root ?? throw new RuntimeException('O arquivo já foi fechado.');
    }

    private function extract(): void
    {
        if (! is_file($this->path)) {
            throw new RuntimeException('Arquivo não encontrado: ' . $this->path);
        }

        $zip = new ZipArchive;

        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('Não consegui abrir o arquivo (zip inválido ou corrompido): ' . $this->path);
        }

        $root = rtrim(sys_get_temp_dir(), '/') . '/gitbook-archive-' . bin2hex(random_bytes(8));

        if (! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Não consegui criar o diretório temporário para extrair o arquivo.');
        }

        $extracted = $zip->extractTo($root);
        $zip->close();

        if (! $extracted) {
            $this->deleteTree($root);

            throw new RuntimeException('Não consegui extrair o arquivo — verifique o espaço em disco e as permissões de ' . sys_get_temp_dir() . '.');
        }

        $this->root = $root;

        $version = (int) ($this->manifest()['format'] ?? 0);

        if ($version !== GitbookArchiveFormat::VERSION) {
            $this->close();

            // Refused rather than guessed at. A backup that half-restores is
            // worse than one that says it cannot: the half that came across
            // looks like a complete restore.
            throw new RuntimeException(
                'Formato de arquivo não suportado (' . $version . '; esta versão lê ' . GitbookArchiveFormat::VERSION . ').'
            );
        }
    }

    /** @return array<mixed> */
    private function json(string $relative): array
    {
        $path = $this->root() . '/' . $relative;

        if (! is_file($path)) {
            throw new RuntimeException('Arquivo incompleto: falta ' . $relative . '.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Arquivo corrompido: ' . $relative . ' não é JSON válido.');
        }

        return $decoded;
    }

    private function deleteTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
