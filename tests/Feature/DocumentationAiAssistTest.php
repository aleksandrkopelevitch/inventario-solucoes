<?php

use App\Enums\UserRole;
use App\Jobs\GenerateDocumentationDraft;
use App\Models\DocumentationAiGeneration;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function assistAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

function assistPage(Solution $solution): DocumentationPage
{
    return DocumentationPage::factory()->for($solution, 'container')->create();
}

it('renders the assistant side panel with the context document list', function () {
    $solution = Solution::factory()->create();
    $page = assistPage($solution);

    $response = $this->actingAs(assistAdmin())
        ->getJson(route('solutions.docs.assist.panel', [$solution, $page]))
        ->assertOk();

    expect($response->json('content'))
        ->toContain('Assiste IA')
        ->toContain('context-documents-slot')
        ->toContain('data-ak-docs-ai-generate');
});

it('dispatches a generation job and returns a poll url', function () {
    Queue::fake();
    $solution = Solution::factory()->create();
    $page = assistPage($solution);

    $response = $this->actingAs(assistAdmin())
        ->postJson(route('solutions.docs.assist.generate', [$solution, $page]), [
            'prompt'           => 'documente o fluxo',
            'media_ids'        => [],
            'existing_content' => '# atual',
        ])->assertOk();

    expect($response->json('status'))->toBe('pending')
        ->and($response->json('pollUrl'))->toContain('/status');

    Queue::assertPushed(GenerateDocumentationDraft::class);
    $this->assertDatabaseHas('documentation_ai_generations', [
        'solution_id' => $solution->id,
        'status'      => 'pending',
        'prompt'      => 'documente o fluxo',
    ]);
});

it('reports pending, then the generated markdown, via the status endpoint', function () {
    $solution = Solution::factory()->create();
    $page = assistPage($solution);
    $gen = DocumentationAiGeneration::create([
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
        'user_id'     => assistAdmin()->id,
        'status'      => 'pending',
        'prompt'      => 'x',
    ]);

    $this->actingAs(assistAdmin())
        ->getJson(route('solutions.docs.assist.status', [$solution, $gen]))
        ->assertOk()->assertJson(['pending' => true]);

    $gen->update(['status' => 'completed', 'result' => '# pronto']);

    $this->actingAs(assistAdmin())
        ->getJson(route('solutions.docs.assist.status', [$solution, $gen]))
        ->assertOk()->assertJson(['pending' => false, 'result' => '# pronto']);
});

it('404s a status request for a generation of another solution', function () {
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $page = assistPage($solution);
    $gen = DocumentationAiGeneration::create([
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $other->id, // belongs to another solution
        'user_id'     => assistAdmin()->id,
        'status'      => 'completed',
        'result'      => 'segredo',
        'prompt'      => 'x',
    ]);

    $this->actingAs(assistAdmin())
        ->getJson(route('solutions.docs.assist.status', [$solution, $gen]))
        ->assertNotFound();
});

it('rejects a second generation for the same target while one is pending', function () {
    Queue::fake();
    $solution = Solution::factory()->create();
    $page = assistPage($solution);
    $admin = assistAdmin();

    $this->actingAs($admin)
        ->postJson(route('solutions.docs.assist.generate', [$solution, $page]), ['prompt' => 'primeiro'])
        ->assertOk();

    // A second request (different prompt) must NOT silently reuse the first
    // one's draft — that would serve the result of the wrong prompt. Nor can
    // it create a second record/job (WithoutOverlapping, keyed by the
    // target, would just burn through the tries). Signal and ask to wait (409).
    $second = $this->actingAs($admin)
        ->postJson(route('solutions.docs.assist.generate', [$solution, $page]), ['prompt' => 'segundo'])
        ->assertStatus(409);
    expect($second->json('message'))->toContain('sendo gerado');

    expect(DocumentationAiGeneration::count())->toBe(1);
    Queue::assertPushed(GenerateDocumentationDraft::class, 1);
});

it('returns a generic error, never the raw exception, for a failed generation', function () {
    $solution = Solution::factory()->create();
    $page = assistPage($solution);
    $gen = DocumentationAiGeneration::create([
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
        'user_id'     => assistAdmin()->id,
        'status'      => 'failed',
        'error'       => 'cURL error 28: connect to https://api.anthropic.com/v1/messages',
        'prompt'      => 'x',
    ]);

    $response = $this->actingAs(assistAdmin())
        ->getJson(route('solutions.docs.assist.status', [$solution, $gen]))
        ->assertOk()
        ->assertJson(['pending' => false, 'failed' => true]);

    expect($response->json('error'))
        ->not->toContain('api.anthropic.com')
        ->not->toContain('cURL');
});

it('rejects an empty prompt with a warning', function () {
    $solution = Solution::factory()->create();
    $page = assistPage($solution);

    $response = $this->actingAs(assistAdmin())
        ->postJson(route('solutions.docs.assist.generate', [$solution, $page]), ['prompt' => ''])
        ->assertStatus(422)->assertJson(['type' => 'warning']);

    expect($response->json('message'))->not->toBeEmpty();
});

it('forbids a non-admin from generating', function () {
    $solution = Solution::factory()->create();
    $page = assistPage($solution);
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->postJson(route('solutions.docs.assist.generate', [$solution, $page]), ['prompt' => 'oi'])
        ->assertForbidden();
});

it('stores a context document on the solution and returns the list slot', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create();

    $response = $this->actingAs(assistAdmin())
        ->post(route('solutions.docs.context.store', $solution), [
            'file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ])->assertOk();

    expect($solution->fresh()->getMedia(Solution::CONTEXT_COLLECTION))->toHaveCount(1)
        ->and($response->json('updatableSlots.0.id'))->toBe('context-documents-slot');
});

it('removes a context document from the solution', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create();
    $media = $solution->addMediaFromString('x')->usingFileName('a.txt')->toMediaCollection(Solution::CONTEXT_COLLECTION);

    $this->actingAs(assistAdmin())
        ->deleteJson(route('solutions.docs.context.destroy', [$solution, $media->id]))
        ->assertOk();

    expect($solution->fresh()->getMedia(Solution::CONTEXT_COLLECTION))->toHaveCount(0);
});
