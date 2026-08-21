<?php

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateSubmissionChatReply;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionChat;
use App\Models\SubmissionSource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($this->user);
});

function ownedSubmission(array $attributes = []): Submission
{
    return Submission::factory()->withSections()->create([
        'created_by_id' => test()->user->id,
        ...$attributes,
    ]);
}

it('lists submissions and returns the slots for an ajax filter', function () {
    ownedSubmission(['name' => 'CATI SKBridge', 'status' => SubmissionStatus::Submitted]);
    ownedSubmission(['name' => 'CATI Fiscal', 'status' => SubmissionStatus::Draft]);

    $this->get(route('submissions.index'))->assertOk()->assertSee('CATI SKBridge');

    $response = $this->getJson(route('submissions.index', ['filter' => ['status' => 'submitted']]))->assertOk();

    $ids = collect($response->json('updatableSlots'))->pluck('id')->all();
    $grid = collect($response->json('updatableSlots'))->firstWhere('id', 'submissions-index-slot')['content'];

    expect($ids)->toBe(['submissions-index-slot', 'submissions-count-slot'])
        ->and($grid)->toContain('CATI SKBridge')
        ->and($grid)->not->toContain('CATI Fiscal');
});

it('renders the filter config as compiled json, not as a literal directive', function () {
    // `@json()` on an x-component tag reaches the browser uncompiled and every
    // filter silently stops working — invisible except in the rendered HTML.
    $html = $this->get(route('submissions.index'))->assertOk()->getContent();

    expect($html)->toContain('data-ak-filters="{&quot;formId&quot;:&quot;submissions-filter-form&quot;')
        ->and($html)->not->toContain('@json(');
});

it('creates a submission with its eleven sections and sends the user to it', function () {
    $response = $this->postJson(route('submissions.store'), ['name' => 'CATI SKBridge'])->assertOk();

    $submission = Submission::firstWhere('name', 'CATI SKBridge');

    expect($submission->slug)->toBe('cati-skbridge')
        ->and($submission->created_by_id)->toBe($this->user->id)
        ->and($submission->sections()->count())->toBe(count(SubmissionSectionKey::cases()))
        ->and($response->json('redirect'))->toBe(route('submissions.show', $submission));
});

it('renders the detail page with the header, checklist, sections and chat', function () {
    $solution = Solution::factory()->create(['name' => 'SkyMob', 'cloud' => 'aws']);
    $submission = ownedSubmission(['name' => 'CATI SKBridge', 'solution_id' => $solution->id]);

    $html = $this->get(route('submissions.show', $submission))->assertOk()->getContent();

    expect($html)->toContain('submission-detail-header-slot')
        ->toContain('submission-checklist-slot')
        ->toContain('submission-sections-slot')
        ->toContain('submission-sources-slot')
        ->toContain('submission-chat-thread-slot')
        // The catalog fact and the deviation question it triggers.
        ->toContain('SkyMob')
        ->toContain('M2C')
        // The composer's hooks must actually render, or sending does nothing.
        ->toContain('data-ak-cati-chat-input')
        ->toContain('data-ak-cati-chat-send');
});

it('hints at attaching material or talking to the assistant on a genuinely fresh submission', function () {
    $html = $this->get(route('submissions.show', ownedSubmission()))->assertOk()->getContent();

    expect($html)->toContain('submission-onboarding-hint')
        ->toContain('Tem um deck ou documento antigo');
});

it('stops hinting once a source is attached, even with every section still blank', function () {
    $submission = ownedSubmission();
    SubmissionSource::factory()->create(['submission_id' => $submission->id]);

    expect($this->get(route('submissions.show', $submission))->getContent())
        ->not->toContain('submission-onboarding-hint');
});

it('stops hinting once a section has content, even with no material attached', function () {
    $submission = ownedSubmission();
    $submission->section(SubmissionSectionKey::Summary)->update(['content' => 'Ponto único de conexão.']);

    expect($this->get(route('submissions.show', $submission))->getContent())
        ->not->toContain('submission-onboarding-hint');
});

it('stops hinting once the person replies in the chat, even with nothing else done', function () {
    $submission = ownedSubmission();

    // First load seeds the assistant's own opening message — that alone must
    // NOT count as "the person engaged", or the banner would never show at all.
    $this->get(route('submissions.show', $submission))->assertOk();
    expect($this->get(route('submissions.show', $submission))->getContent())
        ->toContain('submission-onboarding-hint');

    $chat = $submission->chats()->first();
    $chat->messages()->create(['role' => 'user', 'content' => 'Roda numa VM na Google Cloud.']);

    expect($this->get(route('submissions.show', $submission))->getContent())
        ->not->toContain('submission-onboarding-hint');
});

it('edits one header field in place and refreshes the checklist with it', function () {
    $solution = Solution::factory()->create(['name' => 'SkyMob', 'cloud' => 'gcp']);
    $submission = ownedSubmission();

    $response = $this->patchJson(route('submissions.field.update', $submission), [
        'solution_id' => $solution->id,
    ])->assertOk();

    $ids = collect($response->json('updatableSlots'))->pluck('id')->all();

    expect($submission->fresh()->solution_id)->toBe($solution->id)
        // Linking a solution changes which facts are known, so the checklist
        // has to come back with the header — and the pre-review card too, since
        // setting the status to "submetida" from here starts one.
        ->and($ids)->toBe(['submission-detail-header-slot', 'submission-checklist-slot', 'submission-pre-review-slot'])
        // The checklist is the one that now knows about the solution.
        ->and(collect($response->json('updatableSlots'))->firstWhere('id', 'submission-checklist-slot')['content'])
        ->toContain('SkyMob');
});

it('refuses an unknown status without Laravel\'s default errors shape', function () {
    $submission = ownedSubmission();

    $response = $this->patchJson(route('submissions.field.update', $submission), ['status' => 'quase'])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->not->toBeEmpty()
        ->and($response->json('errors'))->toBeNull();
});

it('marks a section confirmed when a human types it', function () {
    $submission = ownedSubmission();
    $section = $submission->section(SubmissionSectionKey::Summary);

    $response = $this->patchJson(route('submissions.sections.update', [$submission, $section]), [
        'content' => 'Ponto único de conexão.',
    ])->assertOk();

    expect($section->fresh()->state)->toBe(SubmissionSectionState::Confirmed)
        ->and($section->fresh()->updated_by_id)->toBe($this->user->id)
        ->and(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toBe(['submission-sections-slot', 'submission-checklist-slot']);
});

it('takes an emptied section back to blank rather than leaving it confirmed', function () {
    $submission = ownedSubmission();
    $section = $submission->section(SubmissionSectionKey::Summary);
    $section->update(['content' => 'Texto.', 'state' => SubmissionSectionState::Confirmed]);

    $this->patchJson(route('submissions.sections.update', [$submission, $section]), ['content' => ''])->assertOk();

    expect($section->fresh()->state)->toBe(SubmissionSectionState::Empty)
        ->and($section->fresh()->content)->toBeNull();
});

it('refuses to confirm an empty section', function () {
    $submission = ownedSubmission();
    $section = $submission->section(SubmissionSectionKey::Summary);

    $this->postJson(route('submissions.sections.confirm', [$submission, $section]))
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($section->fresh()->state)->toBe(SubmissionSectionState::Empty);
});

it('refuses a section belonging to another submission', function () {
    // Without scopeBindings this would edit someone else's record.
    $mine = ownedSubmission();
    $theirs = ownedSubmission();

    $this->patchJson(
        route('submissions.sections.update', [$mine, $theirs->section(SubmissionSectionKey::Summary)]),
        ['content' => 'Invadido.'],
    )->assertNotFound();
});

it('attaches an uploaded file as material and reads its text', function () {
    Storage::fake('public');

    $submission = ownedSubmission();

    $response = $this->postJson(route('submissions.sources.store', $submission), [
        'file' => UploadedFile::fake()->createWithContent('notas.md', '# Propósito do SKBridge'),
    ])->assertOk();

    $source = $submission->sources()->first();

    expect($source->extracted_text)->toContain('Propósito do SKBridge')
        ->and(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toBe(['submission-sources-slot', 'submission-checklist-slot']);
});

it('warns instead of staying silent when material carries a credential', function () {
    Storage::fake('public');

    $submission = ownedSubmission();

    $response = $this->postJson(route('submissions.sources.store', $submission), [
        'file' => UploadedFile::fake()->createWithContent('notas.md', 'senha=SuperSecreta123'),
    ])->assertOk();

    expect($response->json('message'))->toContain('credencial')
        // Flagged, never removed.
        ->and($submission->sources()->first()->extracted_text)->toContain('SuperSecreta123');
});

it('rejects a file format that is not readable material', function () {
    Storage::fake('public');

    $this->postJson(route('submissions.sources.store', ownedSubmission()), [
        'file' => UploadedFile::fake()->create('planilha.xlsx', 10),
    ])->assertStatus(422)->assertJson(['type' => 'warning']);
});

it('registers a link without fetching it', function () {
    $submission = ownedSubmission();

    $this->postJson(route('submissions.sources.store', $submission), [
        'url'   => 'https://dev.azure.com/leomadeiras/wiki/CATI',
        'label' => 'Wiki do CATI',
    ])->assertOk();

    $source = $submission->sources()->first();

    // Downloading it server-side would be an SSRF surface for no gain.
    expect($source->url)->toBe('https://dev.azure.com/leomadeiras/wiki/CATI')
        ->and($source->extracted_text)->toBeNull();
});

it('detaches material only from the submission it belongs to', function () {
    $mine = ownedSubmission();
    $theirs = ownedSubmission();
    $source = SubmissionSource::factory()->create(['submission_id' => $theirs->id]);

    $this->deleteJson(route('submissions.sources.destroy', [$mine, $source]))->assertNotFound();

    $this->assertModelExists($source);
});

it('queues a reply and shows the pending marker', function () {
    Queue::fake();

    $submission = ownedSubmission();

    $response = $this->postJson(route('submissions.chat.messages.store', $submission), [
        'message' => 'Roda numa VM na Google Cloud.',
    ])->assertOk();

    Queue::assertPushed(GenerateSubmissionChatReply::class);

    expect(collect($response->json('updatableSlots'))->firstWhere('id', 'submission-chat-thread-slot')['content'])
        ->toContain('data-ak-cati-chat-poll');
});

it('refuses a second turn while the first is still generating', function () {
    Queue::fake();

    $submission = ownedSubmission();

    $this->postJson(route('submissions.chat.messages.store', $submission), ['message' => 'Primeira.'])->assertOk();
    $this->postJson(route('submissions.chat.messages.store', $submission), ['message' => 'Segunda.'])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    Queue::assertPushed(GenerateSubmissionChatReply::class, 1);
});

it('stays cheap while polling and returns the thread once the reply lands', function () {
    $submission = ownedSubmission();
    $chat = SubmissionChat::create(['user_id' => $this->user->id, 'submission_id' => $submission->id]);
    $chat->messages()->create(['role' => 'user', 'content' => 'Pergunta.']);

    // Pending: building the slot here would burn a query+render every 2.5s on
    // data the client throws away.
    $this->getJson(route('submissions.chat.status', [$submission, $chat]))
        ->assertOk()
        ->assertJson(['pending' => true, 'updatableSlots' => []]);

    $chat->messages()->create(['role' => 'assistant', 'content' => 'Resposta.']);

    $response = $this->getJson(route('submissions.chat.status', [$submission, $chat]))->assertOk();

    expect($response->json('pending'))->toBeFalse()
        ->and($response->json('updatableSlots.0.content'))->toContain('Resposta.');
});

it('applies a reply\'s drafts into their sections, as drafts', function () {
    $submission = ownedSubmission();
    $chat = SubmissionChat::create(['user_id' => $this->user->id, 'submission_id' => $submission->id]);
    $message = $chat->messages()->create([
        'role'    => 'assistant',
        'content' => 'Preenchi duas seções.',
        'drafts'  => [
            ['key' => 'summary', 'markdown' => 'Resumo proposto.'],
            ['key' => 'objectives', 'markdown' => 'Objetivos propostos.'],
        ],
    ]);

    $response = $this->postJson(route('submissions.chat.messages.apply', [$submission, $message]))->assertOk();

    $summary = $submission->section(SubmissionSectionKey::Summary);

    expect($summary->content)->toBe('Resumo proposto.')
        // Applying is not signing — confirming is a separate gesture.
        ->and($summary->state)->toBe(SubmissionSectionState::Drafted)
        ->and($summary->provenance)->toBe(['source' => 'chat', 'message_id' => $message->id])
        ->and($message->fresh()->applied_at)->not->toBeNull()
        ->and($response->json('message'))->toContain('2 seções');
});

it('exports the ticket text and the document', function () {
    $submission = ownedSubmission(['name' => 'CATI SKBridge']);
    $submission->section(SubmissionSectionKey::Summary)
        ->update(['content' => 'Ponto único.', 'state' => SubmissionSectionState::Confirmed]);

    $ticket = $this->get(route('submissions.export.ticket', $submission))->assertOk();
    $document = $this->get(route('submissions.export.markdown', $submission))->assertOk();

    expect($ticket->headers->get('content-type'))->toContain('text/markdown')
        ->and($ticket->getContent())->toContain('### 1. Resumo da Proposta')
        ->and($ticket->getContent())->toContain('* [x] Resumo da proposta preenchido')
        ->and($document->getContent())->toContain('# CATI SKBridge')
        ->and($document->headers->get('content-disposition'))->toContain('cati-skbridge.md');
});

it('lets a viewer read but not write', function () {
    $submission = ownedSubmission();
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($viewer);

    $this->get(route('submissions.show', $submission))->assertOk();
    $this->patchJson(route('submissions.field.update', $submission), ['name' => 'Renomeada'])->assertForbidden();
    $this->postJson(route('submissions.chat.messages.store', $submission), ['message' => 'Oi'])->assertForbidden();
});

it('resolves create before the slug binding', function () {
    // `submissions/create` must stay above `submissions/{submission}`, or the
    // binding looks for a submission whose slug is "create".
    $this->getJson(route('submissions.create'))->assertOk()->assertJsonStructure(['content']);
});

it('offers only a name and a solution on the creation panel', function () {
    // Everything else is filled in on the detail header once the record
    // exists — asking for it here would mean filling it in twice, once blind.
    $html = $this->getJson(route('submissions.create'))->assertOk()->json('content');

    expect($html)->toContain('name="name"')
        ->toContain('name="solution_id"')
        ->not->toContain('name="requester_person_id"')
        ->not->toContain('name="committee_date"')
        ->not->toContain('name="ticket_reference"')
        ->not->toContain('name="status"');
});

it('ignores fields that only the header may set, even if someone posts them', function () {
    $solution = Solution::factory()->create();

    $this->postJson(route('submissions.store'), [
        'name'                => 'CATI SKBridge',
        'solution_id'         => $solution->id,
        'requester_person_id' => 999,
        'ticket_reference'    => 'INC-1',
        'status'              => 'approved',
    ])->assertOk();

    $submission = Submission::firstWhere('name', 'CATI SKBridge');

    expect($submission->solution_id)->toBe($solution->id)
        ->and($submission->requester_person_id)->toBeNull()
        ->and($submission->ticket_reference)->toBeNull()
        ->and($submission->status)->toBe(SubmissionStatus::Draft);
});

it('has no standalone edit page — every field lives on the detail header', function () {
    expect(fn () => route('submissions.edit', ownedSubmission()))->toThrow(RouteNotFoundException::class)
        ->and(fn () => route('submissions.update', ownedSubmission()))->toThrow(RouteNotFoundException::class);
});

it('hands the chat a submission that is already in memory', function () {
    // What keeps SeedSubmissionChatOpening from re-fetching a record the page
    // has already loaded in full — and re-walking its relations one query at a
    // time, with strict mode unable to complain about a single-row fetch.
    $response = $this->get(route('submissions.show', ownedSubmission()))->assertOk();

    $chat = $response->viewData('chat');

    expect($chat->relationLoaded('submission'))->toBeTrue()
        ->and($chat->submission->relationLoaded('sections'))->toBeTrue()
        ->and($chat->submission->relationLoaded('solution'))->toBeTrue();
});
