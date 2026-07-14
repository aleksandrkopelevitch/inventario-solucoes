<?php

use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Integration;
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

/** Cria uma página de documentação já pendurada numa Solution. */
function publicSolutionPage(Solution $solution, ?string $documentation = null): DocumentationPage
{
    return DocumentationPage::factory()->for($solution, 'container')->create(['documentation' => $documentation]);
}

/*
|--------------------------------------------------------------------------
| Gerar / revogar o link público (admin)
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
| Página pública (sem auth)
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
    // Slug só é único DENTRO do container (unique composto em
    // documentation_pages) — duas soluções podem ter cada uma uma página
    // "teste". A rota pública não pode confiar em model binding global por
    // slug, senão pega a de menor id e 404a por dono errado.
    // A página de menor id pertence a OUTRA solução de propósito — um
    // binding global por slug pegaria essa primeiro.
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

it('lists the solution pages and its integrations in the public sidebar', function () {
    $solution = Solution::factory()->create(['public_token' => 'tok-side', 'name' => 'Minha Solução']);
    publicSolutionPage($solution, '# Visão geral');
    $integration = Integration::factory()->create([
        'name'  => 'Integração Alfa',
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$solution, 0]]);

    $this->get(route('public.docs.solution', $solution->public_token))
        ->assertOk()
        ->assertSee('Minha Solução')
        ->assertSee('Visão geral')
        ->assertSee('Integração Alfa')
        ->assertSee(route('public.docs.integration', [$solution->public_token, $integration]), false);
});

it('renders a participating integration doc publicly and 404s a non-participating one', function () {
    $solution = Solution::factory()->create(['public_token' => 'tok-int']);
    $mine = Integration::factory()->create([
        'name'          => 'Minha Integração',
        'documentation' => '# Doc da integração',
        'chain'         => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($mine, [[$solution, 0]]);

    $other = Solution::factory()->create();
    $foreign = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $other->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($foreign, [[$other, 0]]);

    $this->get(route('public.docs.integration', [$solution->public_token, $mine]))
        ->assertOk()
        ->assertSee('<h1>Doc da integração', false);

    $this->get(route('public.docs.integration', [$solution->public_token, $foreign]))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Mídia pública
|--------------------------------------------------------------------------
*/

it('serves owned docs media publicly and rewrites /files/ urls to the public route', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create(['public_token' => 'tok-media']);
    $page = publicSolutionPage($solution);
    $media = $page->addMedia(UploadedFile::fake()->image('d.png', 120, 80))->toMediaCollection('docs');

    $page->update(['documentation' => "<figure><img src=\"/files/{$media->id}\" alt=\"x\"></figure>"]);

    // A url /files/ é reescrita para a rota pública no HTML renderizado.
    $this->get(route('public.docs.solution', $solution->public_token))
        ->assertOk()
        ->assertSee(route('public.docs.file', [$solution->public_token, $media->id]), false)
        ->assertDontSee('src="/files/', false);

    // E a rota pública serve o arquivo sem auth.
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
