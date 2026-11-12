<?php

use App\Contracts\Documentable;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use App\Support\Images\PublicImage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Thumbnails of the plain public uploads
|--------------------------------------------------------------------------
|
| A logo, a photo or a hosting picture is stored at upload size and drawn at
| 32–56 px in lists, on the map and in diagram blocks. Every upload gets a
| 256 px WebP beside it, and everything that draws it small uses that.
*/

beforeEach(function () {
    Storage::fake('public');
});

it('makes a thumbnail beside an uploaded logo and draws the list from it', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson(route('companies.store'), [
            'name' => 'Logo grande',
            'kind' => 'vendor',
            'logo' => UploadedFile::fake()->image('logo.png', 1356, 665),
        ])
        ->assertOk();

    $company = Company::where('name', 'Logo grande')->firstOrFail();
    $thumb = PublicImage::thumbnailPath($company->logo_path);

    expect($company->logo_path)->toStartWith('company-logos/')
        ->and($thumb)->toStartWith('company-logos/thumbs/')->toEndWith('.webp');

    Storage::disk('public')->assertExists($thumb);
    expect(Storage::disk('public')->size($thumb))->toBeLessThan(Storage::disk('public')->size($company->logo_path));

    [$width, $height] = getimagesize(Storage::disk('public')->path($thumb));
    expect(max($width, $height))->toBe(PublicImage::THUMB_SIZE);

    // The list draws the thumbnail, not the upload.
    $this->get(route('companies.index'))
        ->assertOk()
        ->assertSee(Storage::disk('public')->url($thumb), false);
});

it('falls back to the original while the thumbnail does not exist, and the backfill makes it', function () {
    // A row written before thumbnails existed: file on disk, no thumbnail.
    $path = UploadedFile::fake()->image('old.png', 800, 400)->store('solution-logos', 'public');
    $solution = Solution::factory()->create(['logo_path' => $path]);

    expect(PublicImage::url($path))->toBe(Storage::disk('public')->url($path));

    // A row whose file is gone is counted, never failed on.
    Solution::factory()->create(['logo_path' => 'solution-logos/gone.png']);

    $this->artisan('images:thumbnails')
        ->expectsOutputToContain('Made 1 thumbnail(s); 0 already there.')
        ->expectsOutputToContain('Skipped 1')
        ->assertSuccessful();

    expect(PublicImage::url($path))->toBe(Storage::disk('public')->url(PublicImage::thumbnailPath($path)));

    $this->artisan('images:thumbnails')
        ->expectsOutputToContain('Made 0 thumbnail(s); 1 already there.')
        ->assertSuccessful();

    expect(PublicImage::url(null))->toBeNull()
        ->and(PublicImage::url('https://example.test/x.png'))->toBe('https://example.test/x.png')
        ->and($solution->fresh()->logo_path)->toBe($path);
});

it('queues the web rendition for a picture pasted onto a diagram canvas', function () {
    Queue::fake();

    $diagram = Diagram::factory()->create();
    $diagram->addMedia(UploadedFile::fake()->image('paste.png', 2400, 1200))
        ->toMediaCollection(Documentable::DOCS_COLLECTION);

    Queue::assertPushed(PerformConversionsJob::class);
});
