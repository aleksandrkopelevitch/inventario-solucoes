<?php

use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function shareAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** Creates a documentation page already attached to a Solution. */
function publicSolutionPage(Solution $solution, ?string $documentation = null): DocumentationPage
{
    return DocumentationPage::factory()->for($solution, 'container')->create(['documentation' => $documentation]);
}

/*
|--------------------------------------------------------------------------
| Generate / revoke the public link (admin)
|--------------------------------------------------------------------------
*/

it('lets an admin generate a public documentation link', function () {
    $solution = Solution::factory()->create(['public_token' => null]);

    $response = $this->actingAs(shareAdmin())
        ->postJson(route('solutions.docs.share', $solution))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($solution->fresh()->public_token)->not->toBeNull()
        ->and($response->json('updatableSlots.0.id'))->toBe('docs-share-slot');
});

it('keeps the same token when sharing is generated twice', function () {
    $solution = Solution::factory()->create(['public_token' => null]);
    $admin = shareAdmin();

    $this->actingAs($admin)->postJson(route('solutions.docs.share', $solution))->assertOk();
    $token = $solution->fresh()->public_token;

    $this->actingAs($admin)->postJson(route('solutions.docs.share', $solution))->assertOk();

    expect($solution->fresh()->public_token)->toBe($token);
});

it('lets an admin revoke the public link', function () {
    $solution = Solution::factory()->create(['public_token' => 'tok-123456']);

    $this->actingAs(shareAdmin())
        ->deleteJson(route('solutions.docs.unshare', $solution))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($solution->fresh()->public_token)->toBeNull();
});

it('forbids a viewer from generating or revoking a public link', function () {
    $solution = Solution::factory()->create(['public_token' => null]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->postJson(route('solutions.docs.share', $solution))->assertForbidden();
    $this->actingAs($viewer)->deleteJson(route('solutions.docs.unshare', $solution))->assertForbidden();

    expect($solution->fresh()->public_token)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Public page (no auth)
|--------------------------------------------------------------------------
*/

it('renders the public solution documentation without auth for a valid token', function () {
    $solution = Solution::factory()->create(['public_token' => 'valid-token-xyz']);
    publicSolutionPage($solution, '# Olá público');

    $this->get(route('public.docs.solution', $solution->public_token))
        ->assertOk()
        ->assertSee('html-content', false)
        ->assertSee('<h1>Olá público', false)
        ->assertSee($solution->name);
});

it('404s the public page for an unknown or revoked token', function () {
    Solution::factory()->create(['public_token' => null]);

    $this->get(route('public.docs.solution', 'nope-not-a-token'))->assertNotFound();
});

it('renders a non-first page of the solution tree publicly', function () {
    $solution = Solution::factory()->create(['public_token' => 'tok-tree']);
    publicSolutionPage($solution, '# Primeira')->update(['position' => 0]);
    $second = publicSolutionPage($solution, '# Segunda');
    $second->update(['position' => 1]);

    $this->get(route('public.docs.page', [$solution->public_token, $second]))
        ->assertOk()
        ->assertSee('<h1>Segunda', false);
});

it('resolves the right page when two different solutions each have a page with the same slug', function () {
    // Slug is only unique WITHIN the container (composite unique on
    // documentation_pages) — two solutions can each have a page named
    // "teste". The public route can't rely on global model binding by
    // slug, or it'll grab the lowest-id one and 404 for the wrong owner.
    // The lowest-id page deliberately belongs to ANOTHER solution — a
    // global binding by slug would grab that one first.
    $solutionB = Solution::factory()->create(['public_token' => 'tok-dup-b']);
    DocumentationPage::factory()->for($solutionB, 'container')->create(['slug' => 'teste', 'documentation' => '# Da solução B']);

    $solutionA = Solution::factory()->create(['public_token' => 'tok-dup-a']);
    $pageA = DocumentationPage::factory()->for($solutionA, 'container')->create(['slug' => 'teste', 'documentation' => '# Da solução A']);

    $this->get(route('public.docs.page', [$solutionA->public_token, $pageA]))
        ->assertOk()
        ->assertSee('<h1>Da solução A', false);
});

it('404s a page that belongs to a different solution', function () {
    $solution = Solution::factory()->create(['public_token' => 'tok-a']);
    $other = Solution::factory()->create();
    $foreignPage = publicSolutionPage($other, '# Alheia');

    $this->get(route('public.docs.page', [$solution->public_token, $foreignPage]))
        ->assertNotFound();
});

it('lists the solution pages in the public sidebar, and nothing else', function () {
    $solution = Solution::factory()->create(['public_token' => 'tok-side', 'name' => 'Minha Solução']);
    $page = publicSolutionPage($solution, '# Visão geral');

    // A drawing the solution takes part in is deliberately NOT an entry here:
    // the public surface renders documentation, and a diagram carries none. It
    // reaches a visitor only as an image embedded in a page.
    $diagram = Diagram::factory()->create([
        'name'  => 'Integração Alfa',
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$solution, 0]]);

    $this->get(route('public.docs.solution', $solution->public_token))
        ->assertOk()
        ->assertSee('Minha Solução')
        ->assertSee($page->title)
        ->assertDontSee('Integração Alfa');
});

/*
|--------------------------------------------------------------------------
| Public media
|--------------------------------------------------------------------------
*/

it('serves owned docs media publicly and rewrites /files/ urls to the public route', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create(['public_token' => 'tok-media']);
    $page = publicSolutionPage($solution);
    $media = $page->addMedia(UploadedFile::fake()->image('d.png', 120, 80))->toMediaCollection('docs');

    $page->update(['documentation' => "<figure><img src=\"/files/{$media->id}\" alt=\"x\"></figure>"]);

    // The /files/ url is rewritten to the public route in the rendered HTML.
    $this->get(route('public.docs.solution', $solution->public_token))
        ->assertOk()
        ->assertSee(route('public.docs.file', [$solution->public_token, $media->id]), false)
        ->assertDontSee('src="/files/', false);

    // And the public route serves the file without auth.
    $this->get(route('public.docs.file', [$solution->public_token, $media->id]))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

it('404s public media that does not belong to the shared solution', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create(['public_token' => 'tok-a']);
    $other = Solution::factory()->create(['public_token' => 'tok-b']);
    $otherPage = publicSolutionPage($other);
    $foreignMedia = $otherPage->addMedia(UploadedFile::fake()->image('o.png'))->toMediaCollection('docs');

    $this->get(route('public.docs.file', [$solution->public_token, $foreignMedia->id]))
        ->assertNotFound();
});
