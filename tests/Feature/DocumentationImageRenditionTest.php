<?php

use App\Contracts\Documentable;
use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The lighter rendition of a documentation image
|--------------------------------------------------------------------------
|
| Every PNG/JPEG/WebP in a page gets a WebP copy at most 1600px wide, made on
| the queue (sync here). An `<img>` is served that copy; a link, a download and
| the lightbox (`?original=1`) get the upload. The URL is the same either way.
*/

beforeEach(function () {
    Storage::fake('public');
});

/**
 * A real image on disk. Part of it is noise, so the PNG is as heavy as a real
 * screenshot instead of a flat block the encoder squeezes to nothing — the
 * rendition is only served when it is actually lighter.
 */
function renditionSource(int $width, int $height, string $type = 'png', bool $transparent = false): string
{
    $im = imagecreatetruecolor($width, $height);

    if ($transparent) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    }

    mt_srand(42);
    for ($i = 0; $i < 60; $i++) {
        $x = mt_rand(40, $width - 40);
        $y = mt_rand(40, $height - 40);
        imagefilledrectangle($im, $x, $y, min($width - 1, $x + mt_rand(10, 200)), min($height - 1, $y + mt_rand(10, 120)), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    }
    for ($x = 40; $x < min($width - 40, 640); $x++) {
        for ($y = 40; $y < min($height - 40, 340); $y++) {
            imagesetpixel($im, $x, $y, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }

    $path = sys_get_temp_dir() . '/rendition-' . uniqid() . '.' . $type;
    match ($type) {
        'png'  => imagepng($im, $path),
        'jpg'  => imagejpeg($im, $path, 95),
        'gif'  => imagegif($im, $path),
        'webp' => imagewebp($im, $path, 95),
    };

    return $path;
}

function imageOnPage(DocumentationPage $page, string $path): Media
{
    return $page->addMedia($path)->toMediaCollection(Documentable::DOCS_COLLECTION)->fresh();
}

function renditionPage(?Notebook $notebook = null): DocumentationPage
{
    return DocumentationPage::factory()->for($notebook ?? Notebook::factory()->create())->create();
}

function renditionAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('makes a WebP at most 1600px wide, and never enlarges a small image', function () {
    $page = renditionPage();
    $large = imageOnPage($page, renditionSource(2400, 1200));
    $small = imageOnPage($page, renditionSource(300, 200, 'jpg'));

    expect($large->hasGeneratedConversion(Documentable::WEB_CONVERSION))->toBeTrue();

    [$width, $height, $type] = getimagesize($large->getPath(Documentable::WEB_CONVERSION));
    expect([$width, $height, $type])->toBe([1600, 800, IMAGETYPE_WEBP])
        ->and(getimagesize($small->getPath(Documentable::WEB_CONVERSION))[0])->toBe(300)
        // The upload itself is untouched.
        ->and(getimagesize($large->getPath())[0])->toBe(2400);
});

it('keeps a transparent logo transparent', function () {
    $media = imageOnPage(renditionPage(), renditionSource(400, 200, 'png', transparent: true));

    $webp = imagecreatefromwebp($media->getPath(Documentable::WEB_CONVERSION));

    // A corner pixel the source never painted: fully transparent, not black —
    // which is what a card's logo would otherwise sit on.
    expect((imagecolorat($webp, 0, 0) >> 24) & 0x7F)->toBe(127);
});

it('leaves a GIF, an SVG and a PDF without a rendition', function () {
    $page = renditionPage();
    $gif = imageOnPage($page, renditionSource(300, 200, 'gif'));
    $svg = $page->addMediaFromString('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>')
        ->usingFileName('logo.svg')->toMediaCollection(Documentable::DOCS_COLLECTION);
    $pdf = $page->addMediaFromString("%PDF-1.4\n%%EOF")
        ->usingFileName('manual.pdf')->toMediaCollection(Documentable::DOCS_COLLECTION);

    foreach ([$gif, $svg, $pdf] as $media) {
        expect($media->fresh()->hasGeneratedConversion(Documentable::WEB_CONVERSION))->toBeFalse();
    }
});

it('serves an <img> the rendition and a link, a script or ?original=1 the upload', function () {
    $media = imageOnPage(renditionPage(), renditionSource(2400, 1200));
    $this->actingAs(renditionAdmin());

    $this->get(route('files.show', $media), ['Sec-Fetch-Dest' => 'image'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Vary', 'Sec-Fetch-Dest');

    // Following a link to the file (or downloading it) is a document request.
    $this->get(route('files.show', $media), ['Sec-Fetch-Dest' => 'document'])
        ->assertHeader('Content-Type', 'image/png');

    // No header at all: heavier, never wrong.
    $this->get(route('files.show', $media))
        ->assertHeader('Content-Type', 'image/png');

    // The lightbox's preload is an image request too, and asks explicitly.
    $this->get(route('files.show', [$media, 'original' => 1]), ['Sec-Fetch-Dest' => 'image'])
        ->assertHeader('Content-Type', 'image/png');
});

it('answers a browser that already has the file with a bodiless 304', function () {
    $media = imageOnPage(renditionPage(), renditionSource(2400, 1200));
    $this->actingAs(renditionAdmin());

    $first = $this->get(route('files.show', $media), ['Sec-Fetch-Dest' => 'image']);
    $lastModified = $first->headers->get('Last-Modified');

    expect($lastModified)->not->toBeNull();

    $cached = $this->get(route('files.show', $media), ['Sec-Fetch-Dest' => 'image', 'If-Modified-Since' => $lastModified])
        ->assertStatus(304);

    // A file response has no content to read back; what it would SEND is the
    // body, so that is what gets captured.
    ob_start();
    $cached->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('');

    // Older than the file: it changed since, so the file comes back whole.
    $this->get(route('files.show', $media), ['Sec-Fetch-Dest' => 'image', 'If-Modified-Since' => 'Mon, 01 Jan 2001 00:00:00 GMT'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
});

it('serves the upload while the rendition is still on the queue', function () {
    Queue::fake();

    $media = imageOnPage(renditionPage(), renditionSource(2400, 1200));

    Queue::assertPushed(PerformConversionsJob::class);

    $this->actingAs(renditionAdmin())
        ->get(route('files.show', $media), ['Sec-Fetch-Dest' => 'image'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('serves the upload when the rendition came out heavier', function () {
    $media = imageOnPage(renditionPage(), renditionSource(2400, 1200));

    // A small palette PNG can grow in the WebP encoder; stand one in.
    file_put_contents($media->getPath(Documentable::WEB_CONVERSION), str_repeat('x', $media->size + 1));

    $this->actingAs(renditionAdmin())
        ->get(route('files.show', $media), ['Sec-Fetch-Dest' => 'image'])
        ->assertHeader('Content-Type', 'image/png');
});

it('serves the rendition on /docs and on the magic link too', function () {
    $notebook = Notebook::factory()->published()->create(['public_token' => 'tok123456789']);
    $media = imageOnPage(renditionPage($notebook), renditionSource(2400, 1200));

    $this->get(route('public.docs.file', ['tok123456789', $media]), ['Sec-Fetch-Dest' => 'image'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');

    $this->get(route('public.docs.file', ['tok123456789', $media, 'original' => 1]), ['Sec-Fetch-Dest' => 'image'])
        ->assertHeader('Content-Type', 'image/png');

    $this->actingAs(User::factory()->create())
        ->get(route('docs.file', [$notebook, $media]), ['Sec-Fetch-Dest' => 'image'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
});

it('queues a rendition only for the documentation images that have none', function () {
    $page = renditionPage();
    $done = imageOnPage($page, renditionSource(800, 400));

    Queue::fake();

    $pending = imageOnPage($page, renditionSource(800, 400));
    $gone = imageOnPage($page, renditionSource(800, 400));
    unlink($gone->getPath());
    imageOnPage($page, renditionSource(300, 200, 'gif'));
    // A diagram's pasted image lives in a collection named `docs` as well, and
    // has no rendition to make.
    Diagram::factory()->create()->addMedia(renditionSource(800, 400))->toMediaCollection(Documentable::DOCS_COLLECTION);

    $uploads = Queue::pushed(PerformConversionsJob::class)->count();

    $this->artisan('documentation:web-images')
        ->expectsOutputToContain('Queued 1 documentation image(s)')
        ->expectsOutputToContain('Skipped 1 whose file is missing on disk')
        ->assertSuccessful();

    $queued = Queue::pushed(PerformConversionsJob::class)->slice($uploads);

    // The job keeps its media protected and offers no accessor.
    $mediaOf = fn (PerformConversionsJob $job): Media => (fn () => $this->media)->call($job);

    expect($queued)->toHaveCount(1)
        ->and($mediaOf($queued->first())->is($pending))->toBeTrue()
        ->and($done->hasGeneratedConversion(Documentable::WEB_CONVERSION))->toBeTrue();
});

it('loads the images of a page lazily', function () {
    $html = app(GitbookRenderer::class)->render(
        "<figure><img src=\"/files/1\" alt=\"Tela\"><figcaption>Tela</figcaption></figure>\n\n"
        . "```html\n<img src=\"/files/2\">\n```\n\n"
        . "{% cards cols=\"3\" %}\n{% card title=\"Um\" image=\"/files/3\" %}\n{% endcard %}\n{% endcards %}",
    );

    expect($html)->toContain('<img src="/files/1" alt="Tela" loading="lazy">')
        // Inside a code block it is text, and stays text.
        ->and($html)->toContain('&lt;img src=&quot;/files/2&quot;&gt;')
        // A card already says how it loads; it does not get said twice.
        ->and(substr_count($html, 'loading='))->toBe(2);
});
