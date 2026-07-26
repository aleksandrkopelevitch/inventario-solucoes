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
        ->toContain('Especialista em Documentação')
        ->toContain('context-documents-slot')
        ->toContain('data-ak-docs-ai-generate')
        // The context document uploads automatically on selection (no separate
        // "Anexar" click), pointed at the store endpoint.
        ->toContain('data-ak-context-upload')
        ->toContain(route('solutions.docs.context.store', $solution));
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

it('returns the existing_content and a consume url so the client can resume/diff', function () {
    Queue::fake();
    $solution = Solution::factory()->create();
    $page = assistPage($solution);

    // The generate response carries the consume url (used to resolve the
    // generation so it won't resume on reload).
    $generate = $this->actingAs(assistAdmin())
        ->postJson(route('solutions.docs.assist.generate', [$solution, $page]), [
            'prompt'           => 'x',
            'existing_content' => '# antes',
        ])->assertOk();
    expect($generate->json('consumeUrl'))->toContain('/consume');

    $gen = DocumentationAiGeneration::first();
    $gen->update(['status' => 'completed', 'result' => '# depois']);

    // The status endpoint returns the "before" (existing_content) so the client
    // can diff even after a reload, when the submit-time snapshot is gone.
    $this->actingAs(assistAdmin())
        ->getJson(route('solutions.docs.assist.status', [$solution, $gen]))
        ->assertOk()
        ->assertJson(['result' => '# depois', 'existing_content' => '# antes']);
});

it('marks a generation consumed and 404s / forbids the same as status', function () {
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $page = assistPage($solution);
    $admin = assistAdmin();

    $make = fn (Solution $s) => DocumentationAiGeneration::create([
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $s->id,
        'user_id'     => $admin->id,
        'status'      => 'completed',
        'result'      => '# r',
        'prompt'      => 'x',
    ]);

    $gen = $make($solution);
    $this->actingAs($admin)
        ->postJson(route('solutions.docs.assist.consume', [$solution, $gen]))
        ->assertOk()->assertJson(['ok' => true]);
    expect($gen->fresh()->consumed_at)->not->toBeNull();

    // Cross-solution mismatch 404s (same guard as status).
    $mismatch = $make($other);
    $this->actingAs($admin)
        ->postJson(route('solutions.docs.assist.consume', [$solution, $mismatch]))
        ->assertNotFound();

    // Non-admin can't consume.
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);
    $this->actingAs($viewer)
        ->postJson(route('solutions.docs.assist.consume', [$solution, $make($solution)]))
        ->assertForbidden();
});

it('renders a resume marker on the editor when an unconsumed generation exists', function () {
    $solution = Solution::factory()->create();
    $page = assistPage($solution);
    $admin = assistAdmin();

    // No generation → no marker.
    $this->actingAs($admin)
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-ai-resume', false);

    $gen = DocumentationAiGeneration::create([
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
        'user_id'     => $admin->id,
        'status'      => 'pending',
        'prompt'      => 'x',
    ]);

    // A recent pending generation → marker present, flagged pending.
    $this->actingAs($admin)
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertSee('data-ak-docs-ai-resume', false)
        ->assertSee('data-pending="1"', false);

    // Once consumed, it no longer resumes.
    $gen->update(['status' => 'completed', 'result' => '# r', 'consumed_at' => now()]);
    $this->actingAs($admin)
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-ai-resume', false);
});

it('does not resume another user\'s generation', function () {
    $solution = Solution::factory()->create();
    $page = assistPage($solution);

    DocumentationAiGeneration::create([
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
        'user_id'     => assistAdmin()->id, // a different admin
        'status'      => 'pending',
        'prompt'      => 'x',
    ]);

    $this->actingAs(assistAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-ai-resume', false);
});

it('reaps a generation orphaned mid-job instead of resuming it as pending', function () {
    $solution = Solution::factory()->create();
    $page = assistPage($solution);
    $admin = assistAdmin();

    // Worker killed mid-job (`composer dev` restarted): the record never leaves
    // `pending` on its own. The reap happens on the record the page is about to
    // resume — the marker must offer it as a finished (failed) generation, not
    // as one still generating, or the editor locks itself waiting forever.
    $gen = DocumentationAiGeneration::create([
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
        'user_id'     => $admin->id,
        'status'      => 'pending',
        'prompt'      => 'x',
    ]);
    $gen->forceFill(['created_at' => now()->subSeconds((int) config('services.documentation_ai.stale_after') + 60)])->save();

    $this->actingAs($admin)
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertSee('data-ak-docs-ai-resume', false)
        ->assertSee('data-pending="0"', false);

    expect($gen->fresh()->status)->toBe('failed')
        ->and($gen->fresh()->error)->toBe(DocumentationAiGeneration::INTERRUPTED_ERROR);
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

it('reaps an orphaned pending generation so a new draft can be created for the same target', function () {
    Queue::fake();
    $solution = Solution::factory()->create();
    $page = assistPage($solution);
    $admin = assistAdmin();

    // A worker killed mid-job (e.g. `composer dev` restarted) never ran
    // handle()/failed(), so this record is stuck 'pending' from long ago.
    $orphan = DocumentationAiGeneration::create([
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
        'user_id'     => $admin->id,
        'status'      => 'pending',
        'prompt'      => 'antigo',
    ]);
    $orphan->forceFill(['created_at' => now()->subSeconds(1000)])->save();

    // Without reaping, this would 409; the orphan must not block a fresh draft.
    $this->actingAs($admin)
        ->postJson(route('solutions.docs.assist.generate', [$solution, $page]), ['prompt' => 'novo'])
        ->assertOk()
        ->assertJson(['status' => 'pending']);

    expect($orphan->fresh()->status)->toBe('failed');
    Queue::assertPushed(GenerateDocumentationDraft::class, 1);
});

it('resolves an orphaned pending generation to failed on a status poll', function () {
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
    $gen->forceFill(['created_at' => now()->subSeconds(1000)])->save();

    $this->actingAs(assistAdmin())
        ->getJson(route('solutions.docs.assist.status', [$solution, $gen]))
        ->assertOk()
        ->assertJson(['pending' => false, 'failed' => true]);

    expect($gen->fresh()->status)->toBe('failed');
});

it('keeps blocking a second draft while a recent generation is still pending', function () {
    Queue::fake();
    $solution = Solution::factory()->create();
    $page = assistPage($solution);
    $admin = assistAdmin();

    // A genuinely in-flight (recent) pending generation must still 409 — the
    // staleness reaping must not weaken the concurrency guard.
    DocumentationAiGeneration::create([
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
        'user_id'     => $admin->id,
        'status'      => 'pending',
        'prompt'      => 'em andamento',
    ]);

    $this->actingAs($admin)
        ->postJson(route('solutions.docs.assist.generate', [$solution, $page]), ['prompt' => 'novo'])
        ->assertStatus(409);

    Queue::assertNotPushed(GenerateDocumentationDraft::class);
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
