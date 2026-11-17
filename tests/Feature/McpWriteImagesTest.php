<?php

use App\Enums\AccessModule;
use App\Mcp\OAuth;
use App\Mcp\Support\ImageArgument;
use App\Models\Company;
use App\Models\McpWrite;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->seed(AttributeOptionSeeder::class);
    Storage::fake('public');
    Http::preventStrayRequests();
});

/** One `tools/call` as a catalog Editor holding `mcp:write`, decoded. @return array{isError: bool, text: string, data: ?array} */
function imageTool(string $name, array $arguments, ?User $user = null): array
{
    Passport::actingAs($user ?? User::factory()->editor(AccessModule::Catalog)->create(), [OAuth::SCOPE, OAuth::WRITE_SCOPE]);

    $result = test()->postJson(route('mcp.handle'), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params'  => ['name' => $name, 'arguments' => $arguments],
    ])->assertOk()->json('result');

    return ['isError' => $result['isError'], 'text' => $result['content'][0]['text'], 'data' => json_decode($result['content'][0]['text'], true)];
}

function pngBytes(): string
{
    // Held in a variable: the fake's temp file is deleted once the object is collected.
    $file = UploadedFile::fake()->image('logo.png', 64, 64);

    return file_get_contents($file->getPathname());
}

it('creates a solution with a base64 logo, outside the write log', function () {
    $result = imageTool('create_solution', [
        'name' => 'Vtex', 'category' => 'ERP', 'status' => 'active', 'logo' => base64_encode(pngBytes()),
    ]);

    expect($result['isError'])->toBeFalse();

    $solution = Solution::where('name', 'Vtex')->sole();

    expect($solution->logo_path)->toStartWith('solution-logos/')
        ->and($result['data']['solution']['logo'])->toBe(Storage::disk('public')->url($solution->logo_path))
        ->and(McpWrite::sole()->after)->not->toHaveKey('logo_path');

    Storage::disk('public')->assertExists($solution->logo_path);
})->skip(fn () => ! function_exists('imagecreatetruecolor'), 'GD is needed to fake an image');

it('takes a data URI on update without re-validating the rest of the record', function () {
    $company = Company::factory()->create();

    $result = imageTool('update_company', ['slug' => $company->slug, 'logo' => 'data:image/png;base64,' . base64_encode(pngBytes())]);

    expect($result['isError'])->toBeFalse()
        ->and($result['data']['message'])->toBe('Empresa atualizada.')
        ->and($company->refresh()->logo_path)->toStartWith('company-logos/')
        ->and(McpWrite::count())->toBe(0);
})->skip(fn () => ! function_exists('imagecreatetruecolor'), 'GD is needed to fake an image');

it('downloads a photo from a URL, following a redirect', function () {
    Http::fake([
        'https://8.8.8.8/old.png' => Http::response('', 301, ['Location' => '/new.png']),
        'https://8.8.8.8/new.png' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    $person = Person::factory()->create();

    $result = imageTool('update_person', ['slug' => $person->slug, 'photo' => 'https://8.8.8.8/old.png']);

    expect($result['isError'])->toBeFalse()
        ->and($person->refresh()->photo_path)->toStartWith('photos/');
})->skip(fn () => ! function_exists('imagecreatetruecolor'), 'GD is needed to fake an image');

it('refuses a redirect into the internal network', function () {
    Http::fake(['https://8.8.8.8/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
    $person = Person::factory()->create();

    $result = imageTool('update_person', ['slug' => $person->slug, 'photo' => 'https://8.8.8.8/logo.png']);

    expect($result['isError'])->toBeTrue()
        ->and($result['text'])->toContain('photo:')->toContain('rede interno')
        ->and($person->refresh()->photo_path)->toBeNull();

    Http::assertSentCount(1);
});

it('refuses what is not a JPG, PNG or WebP, and saves nothing else of the call', function () {
    $svg = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $result = imageTool('create_solution', ['name' => 'Vtex', 'category' => 'ERP', 'status' => 'active', 'logo' => $svg]);

    expect($result['isError'])->toBeTrue()
        ->and($result['text'])->toContain('Nada foi salvo')->toContain('logo:')
        ->and(Solution::count())->toBe(0);
});

it('refuses an oversized base64 before decoding it, and garbage as garbage', function () {
    $company = Company::factory()->create();

    $big = imageTool('update_company', ['slug' => $company->slug, 'logo' => str_repeat('A', (int) ceil(ImageArgument::MAX_KILOBYTES * 1024 / 3) * 4 + 4)]);
    $junk = imageTool('update_company', ['slug' => $company->slug, 'logo' => 'not an image!']);

    expect($big['text'])->toContain('passa de 2 MB')
        ->and($junk['text'])->toContain('nem um conteúdo base64 válido');
});

it('clears a logo through clear, beside a field that is logged', function () {
    $solution = Solution::factory()->create(['logo_path' => 'solution-logos/old.png']);

    $result = imageTool('update_solution', ['slug' => $solution->slug, 'description' => 'Nova', 'clear' => ['logo']]);

    expect($result['isError'])->toBeFalse()
        ->and($solution->refresh()->logo_path)->toBeNull()
        ->and($solution->description)->toBe('Nova')
        ->and(McpWrite::sole()->after)->toBe(['description' => 'Nova']);
});

it('says nothing changed when clearing a logo that is not there', function () {
    $company = Company::factory()->create();

    expect(imageTool('update_company', ['slug' => $company->slug, 'clear' => ['logo']])['data']['message'])
        ->toBe('Nada mudou: os valores enviados já eram os atuais.');
});

it('refuses a logo both sent and cleared', function () {
    $company = Company::factory()->create();

    expect(imageTool('update_company', ['slug' => $company->slug, 'logo' => 'https://8.8.8.8/x.png', 'clear' => ['logo']])['text'])
        ->toContain('também foi enviado com um valor');
});
