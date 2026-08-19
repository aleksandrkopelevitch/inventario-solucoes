<?php

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Jobs\PreReviewSubmission;
use App\Models\CatiGuideline;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\User;
use App\Services\Cati\PreReviewPromptBuilder;
use App\Services\Cati\PreReviewService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

function fakePreReview(string $reply): PreReviewService
{
    return new class($reply) extends PreReviewService
    {
        public string $capturedPrompt = '';

        public function __construct(private string $reply)
        {
            parent::__construct(app(PreReviewPromptBuilder::class));
        }

        protected function prompt(string $prompt): AgentResponse
        {
            $this->capturedPrompt = $prompt;

            return new AgentResponse('fake', $this->reply, new Usage(10, 20), new Meta('gemini', 'gemini-3.6-flash'));
        }
    };
}

function preReviewSubject(array $sections = ['plan_costs' => 'Três semanas.'], ?User $user = null): Submission
{
    $submission = Submission::factory()->withSections()->create([
        'created_by_id' => $user?->id ?? User::factory()->create(['role' => UserRole::Admin])->id,
    ]);

    foreach ($sections as $key => $content) {
        $submission->section(SubmissionSectionKey::from($key))->update(['content' => $content]);
    }

    return $submission->fresh();
}

it('reads findings worst-first', function () {
    $service = fakePreReview(<<<'REPLY'
    ````achado:baixa:summary
    O resumo não diz o resultado esperado.
    ````

    ````achado:alta:plan_costs
    O prazo de 3 semanas não considera a janela de homologação de rede.
    ````

    ````achado:media:standards
    Observabilidade citada sem dizer onde os logs ficam.
    ````
    REPLY);

    $result = $service->handle(preReviewSubject());

    // A pre-review nobody reads to the end should still have delivered its
    // worst news.
    expect(array_column($result['findings'], 'severity'))->toBe(['alta', 'media', 'baixa'])
        ->and($result['findings'][0]['section'])->toBe('plan_costs')
        ->and($result['findings'][0]['text'])->toContain('homologação de rede');
});

it('returns nothing when there is nothing to object to', function () {
    $result = fakePreReview('Não encontrei nada além do checklist.')->handle(preReviewSubject());

    expect($result['findings'])->toBe([])
        ->and($result['meta']['model'])->toBe(config('services.cati.model'));
});

it('hands over the automatic analysis precisely so it is not repeated', function () {
    $solution = Solution::factory()->create(['cloud' => 'aws']);
    $submission = preReviewSubject();
    $submission->update(['solution_id' => $solution->id]);

    CatiGuideline::factory()->create(['title' => 'Nuvem alvo', 'content' => 'GCP é a nuvem alvo do M2C.']);

    $service = fakePreReview('');
    $service->handle($submission->fresh());

    expect($service->capturedPrompt)->toContain('NÃO REPITA')
        // The conformance verdicts and the derived questions both go in.
        ->toContain('Nuvem alvo (M2C)')
        ->toContain('nuvem alvo do programa M2C')
        // And the only policy it may invoke.
        ->toContain('GCP é a nuvem alvo do M2C.');
});

it('drops a finding whose section tag is malformed', function () {
    $result = fakePreReview("````achado:altissima:plan_costs\nAlgo.\n````\n\n````achado:alta:plan_costs\nOutro.\n````")
        ->handle(preReviewSubject());

    expect($result['findings'])->toHaveCount(1)
        ->and($result['findings'][0]['text'])->toBe('Outro.')
        // Counted rather than silently swallowed.
        ->and($result['meta']['discarded'])->toBe(1);
});

it('queues a run and refuses a second while one is in flight', function () {
    Queue::fake();
    $this->freezeTime();

    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = preReviewSubject(user: $user);

    $this->postJson(route('submissions.pre-review.store', $submission))->assertOk();
    Queue::assertPushed(PreReviewSubmission::class, 1);

    $this->postJson(route('submissions.pre-review.store', $submission))
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    Queue::assertPushed(PreReviewSubmission::class, 1);
});

it('stops calling itself running once the stall window passes', function () {
    $this->freezeTime();

    $submission = preReviewSubject();
    $submission->update(['pre_review_requested_at' => now()]);

    expect($submission->isPreReviewRunning())->toBeTrue();

    // A worker killed mid-run must not leave the button disabled forever.
    $this->travel(Submission::PRE_REVIEW_STALL_SECONDS + 1)->seconds();

    expect($submission->isPreReviewRunning())->toBeFalse();
});

it('stays cheap while polling and returns the slot once it lands', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = preReviewSubject(user: $user);
    $submission->update(['pre_review_requested_at' => now()]);

    $this->getJson(route('submissions.pre-review.status', $submission))
        ->assertOk()
        ->assertJson(['pending' => true, 'updatableSlots' => []]);

    $submission->update([
        'pre_reviewed_at' => now()->addSecond(),
        'pre_review'      => ['findings' => [['severity' => 'alta', 'section' => 'plan_costs', 'text' => 'Prazo sem base.']]],
    ]);

    $response = $this->getJson(route('submissions.pre-review.status', $submission->fresh()))->assertOk();

    expect($response->json('pending'))->toBeFalse()
        ->and($response->json('updatableSlots.0.content'))->toContain('Prazo sem base.');
});

it('stamps a failed run so the button comes back', function () {
    $submission = preReviewSubject();
    $submission->update(['pre_review_requested_at' => now()]);

    (new PreReviewSubmission($submission))->failed(new RuntimeException('provider down'));

    expect($submission->fresh()->isPreReviewRunning())->toBeFalse()
        ->and($submission->fresh()->preReviewFindings())->toBe([]);
});

it('shows the findings on the page', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = preReviewSubject(user: $user);
    $submission->update([
        'pre_reviewed_at' => now(),
        'pre_review'      => ['findings' => [
            ['severity' => 'alta', 'section' => 'plan_costs', 'text' => 'O prazo não considera a homologação de rede.'],
        ]],
    ]);

    $html = $this->get(route('submissions.show', $submission))->assertOk()->getContent();

    expect($html)->toContain('Prévia do comitê')
        ->toContain('O prazo não considera a homologação de rede.')
        ->toContain('Plano de implementação e custos');
});

it('reads the submission the moment it is submitted', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = preReviewSubject(user: $user);

    // The findings are worth most between submitting and the meeting, which is
    // exactly when nobody thinks to press a button.
    $this->patchJson(route('submissions.field.update', $submission), ['status' => 'submitted'])->assertOk();

    Queue::assertPushed(PreReviewSubmission::class, 1);
    expect($submission->fresh()->isPreReviewRunning())->toBeTrue();
});

it('does not queue another read when an already-submitted record is saved again', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = preReviewSubject(user: $user);
    $submission->update(['status' => SubmissionStatus::Submitted]);

    // Fires on the TRANSITION only — editing the ticket number on a submitted
    // record must not cost another model call.
    $this->patchJson(route('submissions.field.update', $submission), ['ticket_reference' => 'INC-123'])->assertOk();

    Queue::assertNothingPushed();
});

it('does not fire on a status that is not submission', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $this->patchJson(route('submissions.field.update', preReviewSubject(user: $user)), ['status' => 'in_review'])->assertOk();

    Queue::assertNothingPushed();
});
