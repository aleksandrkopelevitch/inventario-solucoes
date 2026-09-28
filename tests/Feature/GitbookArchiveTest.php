<?php

use App\Contracts\Documentable;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Support\Gitbook\GitbookArchive;
use App\Support\Gitbook\GitbookArchiveFormat;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('services.gitbook.token', 'test-token');
    config()->set('services.gitbook.url', 'https://api.gitbook.com/v1');
    config()->set('services.gitbook.timeout', 30);
    config()->set('services.gitbook.max_asset_bytes', 1048576);
    config()->set('services.gitbook.retries', 3);
    config()->set('services.gitbook.retry_sleep', 0);
    Storage::fake('public');

    $this->archiveDir = sys_get_temp_dir() . '/gitbook-archive-test-' . bin2hex(random_bytes(6));
});

afterEach(function () {
    if (is_dir($this->archiveDir)) {
        array_map('unlink', glob($this->archiveDir . '/*') ?: []);
        rmdir($this->archiveDir);
    }
});

/**
 * A space with two pages, one embedded image, and one file nobody references —
 * which is the shape that matters here: the unreferenced one is exactly what an
 * import ignores and a backup must not.
 *
 * `$imageResponse` is a parameter rather than something a test overrides with a
 * second `Http::fake()`, because `Http::fake()` MERGES stubs instead of
 * replacing them: a later stub for a URL the first one already matched never
 * wins, silently. The same trap is written up in `GitbookImportTest`.
 */
// `Http::response()` hands back a promise, not a Response — hence `mixed`.
function fakeSpaceForArchive(array $extraFiles = [], mixed $imageResponse = null): void
{
    Http::fake([
        'api.gitbook.com/v1/spaces/space-1/content/pages*' => Http::response(['pages' => [
            ['id' => 'p1', 'type' => 'document', 'title' => 'Visão geral'],
            ['id' => 'g1', 'type' => 'group', 'title' => 'Detalhes', 'pages' => [
                ['id' => 'p2', 'type' => 'document', 'title' => 'Instalação'],
            ]],
        ]]),
        'api.gitbook.com/v1/spaces/space-1/content/page/*' => function (Request $request) {
            $id = str($request->url())->before('?')->afterLast('/')->value();

            return Http::response(['markdown' => match ($id) {
                'p1'    => "# Visão geral\n\n<figure><img src=\"/files/gbImg\" alt=\"\"><figcaption></figcaption></figure>",
                'p2'    => "# Instalação\n\nSem anexos.",
                default => '',
            }]);
        },
        'api.gitbook.com/v1/spaces/space-1/content/files*' => Http::response(['items' => [
            ['id' => 'gbImg', 'name' => 'diagrama.png', 'downloadURL' => 'https://files.gitbook.com/diagrama.png'],
            ['id' => 'gbSpec', 'name' => 'api.yaml', 'downloadURL' => 'https://files.gitbook.com/api.yaml'],
            ...$extraFiles,
        ]]),
        'api.gitbook.com/v1/spaces/space-1*' => Http::response(['id' => 'space-1', 'title' => 'Manual de Integrações']),
        'files.gitbook.com/diagrama.png'     => $imageResponse ?? Http::response('PNGBYTES', 200, ['Content-Length' => 8]),
        'files.gitbook.com/api.yaml'         => Http::response('openapi: 3.0.0', 200, ['Content-Length' => 14]),
    ]);
}

it('writes one self-contained archive per space', function () {
    fakeSpaceForArchive();

    $this->artisan('gitbook:archive', ['--space' => ['space-1'], '--path' => $this->archiveDir])
        ->assertSuccessful();

    $path = $this->archiveDir . '/' . GitbookArchiveFormat::fileName('Manual de Integrações', 'space-1');
    expect(is_file($path))->toBeTrue();

    $zip = new ZipArchive;
    $zip->open($path);

    $manifest = json_decode($zip->getFromName(GitbookArchiveFormat::MANIFEST), true);

    expect($manifest['space_id'])->toBe('space-1')
        ->and($manifest['space_title'])->toBe('Manual de Integrações')
        ->and($manifest['format'])->toBe(GitbookArchiveFormat::VERSION)
        // Two documents; the `group` has no content to fetch.
        ->and($manifest['pages'])->toBe(2)
        ->and($manifest['asset_failures'])->toBe([]);

    // The API's own answers, kept verbatim — a restore replays the real import
    // against them rather than against this app's reading of them.
    expect($zip->getFromName(GitbookArchiveFormat::pagePath('p1')))->toContain('/files/gbImg')
        ->and(json_decode($zip->getFromName(GitbookArchiveFormat::PAGES), true))->toHaveCount(2);

    $zip->close();
});

it('archives a file nobody references, because a backup is not shaped by the parser', function () {
    fakeSpaceForArchive();

    $this->artisan('gitbook:archive', ['--space' => ['space-1'], '--path' => $this->archiveDir])
        ->assertSuccessful();

    $zip = new ZipArchive;
    $zip->open($this->archiveDir . '/' . GitbookArchiveFormat::fileName('Manual de Integrações', 'space-1'));

    $manifest = json_decode($zip->getFromName(GitbookArchiveFormat::MANIFEST), true);
    $index = json_decode($zip->getFromName(GitbookArchiveFormat::ASSET_INDEX), true);

    // `api.yaml` appears in no page's Markdown — an import would never fetch
    // it. Found for real in the first archived space: a `{% openapi %}` block,
    // which the normalizer down-converts to a callout naming the file, so its
    // spec was referenced in prose and matched no asset pattern.
    expect($manifest['assets'])->toBe(2)
        ->and($manifest['assets_referenced'])->toBe(1)
        ->and($index)->toHaveKey('/files/gbSpec')
        ->and($zip->getFromName($index['/files/gbSpec']['file']))->toBe('openapi: 3.0.0');

    // Recorded so the archive can be checked rather than trusted.
    expect($index['/files/gbImg']['sha256'])->toBe(hash('sha256', 'PNGBYTES'))
        ->and($index['/files/gbImg']['bytes'])->toBe(8);

    $zip->close();
});

it('restores the caderno, its shape and its media without touching the network', function () {
    fakeSpaceForArchive();

    $this->artisan('gitbook:archive', ['--space' => ['space-1'], '--path' => $this->archiveDir])
        ->assertSuccessful();

    $path = $this->archiveDir . '/' . GitbookArchiveFormat::fileName('Manual de Integrações', 'space-1');

    // The whole promise of the archive: from here on, any HTTP call is a bug.
    // `preventStrayRequests()` turns one into a failure instead of a silent
    // dependency on a source that will not exist when this matters.
    Http::fake();
    Http::preventStrayRequests();

    $this->artisan('gitbook:restore', ['archive' => [$path]])->assertSuccessful();

    $notebook = Notebook::where('name', 'Manual de Integrações')->firstOrFail();
    $pages = DocumentationPage::where('notebook_id', $notebook->id)->get();

    // The `group` came across as an empty section page above its child, exactly
    // as a live import would have written it.
    expect($pages->pluck('title')->all())->toEqualCanonicalizing(['Visão geral', 'Detalhes', 'Instalação']);

    $child = $pages->firstWhere('title', 'Instalação');
    $section = $pages->firstWhere('title', 'Detalhes');
    expect($child->parent_id)->toBe($section->id);

    $overview = $pages->firstWhere('title', 'Visão geral');
    $media = $overview->getMedia(Documentable::DOCS_COLLECTION);

    expect($media)->toHaveCount(1)
        ->and($media->first()->file_name)->toBe('diagrama.png')
        // Repointed at OUR media, not left as the GitBook id — the failure this
        // whole asset pipeline exists to prevent.
        ->and($overview->documentation)->toContain('/files/' . $media->first()->id)
        ->and($overview->documentation)->not->toContain('/files/gbImg');
});

it('restores twice without duplicating the caderno', function () {
    fakeSpaceForArchive();

    $this->artisan('gitbook:archive', ['--space' => ['space-1'], '--path' => $this->archiveDir])->assertSuccessful();
    $path = $this->archiveDir . '/' . GitbookArchiveFormat::fileName('Manual de Integrações', 'space-1');

    Http::fake();
    Http::preventStrayRequests();

    $this->artisan('gitbook:restore', ['archive' => [$path]])->assertSuccessful();
    $this->artisan('gitbook:restore', ['archive' => [$path]])->assertSuccessful();

    // Matched by title, the same way a re-import is — a restore is the import
    // with its source swapped, so it inherits that behaviour rather than
    // re-deciding it.
    expect(Notebook::where('name', 'Manual de Integrações')->count())->toBe(1)
        ->and(DocumentationPage::count())->toBe(3);

    // And the media was not accumulated: re-importing replaces the page's
    // content wholesale, so its old embedded media is cleared first.
    $overview = DocumentationPage::where('title', 'Visão geral')->firstOrFail();
    expect($overview->getMedia(Documentable::DOCS_COLLECTION))->toHaveCount(1);
});

it('skips a space whose archive is already there, unless forced', function () {
    fakeSpaceForArchive();

    $this->artisan('gitbook:archive', ['--space' => ['space-1'], '--path' => $this->archiveDir])->assertSuccessful();

    $path = $this->archiveDir . '/' . GitbookArchiveFormat::fileName('Manual de Integrações', 'space-1');
    $first = filemtime($path);

    // This is dozens of spaces and hundreds of downloads over somebody else's
    // API: re-running after a failure must not pay again for what worked.
    $this->artisan('gitbook:archive', ['--space' => ['space-1'], '--path' => $this->archiveDir])
        ->expectsOutputToContain('já existe')
        ->assertSuccessful();

    touch($path, $first - 100);
    $this->artisan('gitbook:archive', ['--space' => ['space-1'], '--path' => $this->archiveDir, '--force' => true])
        ->assertSuccessful();

    expect(filemtime($path))->toBeGreaterThan($first - 100);
});

it('refuses an archive written in a format it does not know', function () {
    $path = $this->archiveDir . '/bogus.zip';
    mkdir($this->archiveDir, 0700, true);

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString(GitbookArchiveFormat::MANIFEST, json_encode(['format' => 99, 'space_id' => 'x']));
    $zip->close();

    // Refused rather than half-read: the half that came across would look like
    // a complete restore, which is the one thing a backup must never do.
    expect(fn () => GitbookArchive::open($path))
        ->toThrow(RuntimeException::class, 'Formato de arquivo não suportado');

    $this->artisan('gitbook:restore', ['archive' => [$path]])->assertFailed();
});

it('reports an asset it could not archive instead of writing an archive that looks complete', function () {
    // The one the page actually embeds refuses to download.
    fakeSpaceForArchive(imageResponse: Http::response('nope', 500));

    $this->artisan('gitbook:archive', ['--space' => ['space-1'], '--path' => $this->archiveDir])
        ->expectsOutputToContain('/files/gbImg')
        ->assertSuccessful();

    $zip = new ZipArchive;
    $zip->open($this->archiveDir . '/' . GitbookArchiveFormat::fileName('Manual de Integrações', 'space-1'));
    $manifest = json_decode($zip->getFromName(GitbookArchiveFormat::MANIFEST), true);

    expect($manifest['asset_failures'])->toHaveCount(1)
        ->and($manifest['asset_failures'][0])->toContain('/files/gbImg');

    $zip->close();
});
