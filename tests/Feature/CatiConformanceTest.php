<?php

use App\Enums\ConformanceVerdict;
use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\User;
use App\Support\Cati\ConformanceChecks;
use App\Support\Cati\DeviationRules;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function checkFor(Submission $submission, string $key): array
{
    return collect(ConformanceChecks::for($submission))->firstWhere('key', $key);
}

function submissionFor(array $solutionAttributes = [], array $sections = [], ?User $user = null): Submission
{
    $solution = $solutionAttributes === [] ? null : Solution::factory()->create($solutionAttributes);

    $submission = Submission::factory()->withSections()->create([
        'solution_id'   => $solution?->id,
        'created_by_id' => $user?->id ?? User::factory()->create(['role' => UserRole::Admin])->id,
    ]);

    foreach ($sections as $key => $content) {
        $submission->section(SubmissionSectionKey::from($key))->update(['content' => $content]);
    }

    return $submission->fresh();
}

it('grades the target cloud, and calls anything else an exception', function () {
    expect(checkFor(submissionFor(['cloud' => 'gcp']), 'cloud_target')['verdict'])->toBe(ConformanceVerdict::Ok)
        ->and(checkFor(submissionFor(['cloud' => 'aws']), 'cloud_target')['verdict'])->toBe(ConformanceVerdict::Violation)
        ->and(checkFor(submissionFor(['cloud' => 'aws']), 'cloud_target')['detail'])->toContain('AWS')
        // No cloud on record is a gap in the CATALOG, not a breach.
        ->and(checkFor(submissionFor(['cloud' => null]), 'cloud_target')['verdict'])->toBe(ConformanceVerdict::Unknown);
});

it('treats silence as a question, never as a breach', function () {
    $silent = submissionFor([], ['standards' => 'Segue o processo da área.']);

    foreach (['observability', 'security', 'sdlc'] as $key) {
        expect(checkFor($silent, $key)['verdict'])->toBe(ConformanceVerdict::Attention);
    }

    $stated = submissionFor([], [
        'standards' => 'Esteira de CI/CD com code review. Logs e tracing no Cloud Logging. Autenticação via IAM e mTLS.',
    ]);

    foreach (['observability', 'security', 'sdlc'] as $key) {
        expect(checkFor($stated, $key)['verdict'])->toBe(ConformanceVerdict::Ok);
    }
});

it('presses on sensitive data only where data leaves the building', function () {
    $saas = submissionFor(['environment' => 'saas'], ['domains_data' => 'Lê pedidos e grava planos.']);
    $onPremise = submissionFor(['environment' => 'on_premise'], ['domains_data' => 'Lê pedidos e grava planos.']);

    expect(checkFor($saas, 'sensitive_data')['verdict'])->toBe(ConformanceVerdict::Attention)
        ->and(checkFor($onPremise, 'sensitive_data')['verdict'])->toBe(ConformanceVerdict::Ok);
});

it('hears a keyword whether or not it was typed with its accents', function () {
    // The lists these check against are Portuguese, and half the submissions
    // are typed without accents. "dados sensiveis" used to read as a
    // submission that never mentioned sensitive data at all, because
    // SENSITIVE_DATA_TERMS carried only the accented spelling — while
    // CONTINGENCY_TERMS happened to carry both. Folding both sides is what
    // makes that impossible to get half-right.
    $bare = submissionFor(['environment' => 'saas'], ['domains_data' => 'Trafega dados sensiveis do cliente.']);
    $accented = submissionFor(['environment' => 'saas'], ['domains_data' => 'Trafega dados sensíveis do cliente.']);
    $shouting = submissionFor(['environment' => 'saas'], ['domains_data' => 'TRAFEGA DADOS SENSIVEIS DO CLIENTE.']);

    foreach ([$bare, $accented, $shouting] as $submission) {
        expect(checkFor($submission, 'sensitive_data')['verdict'])->toBe(ConformanceVerdict::Ok);
    }

    // And the same for the plan the committee asks about, which reads a
    // different list through the same folding.
    $rollback = submissionFor(['criticality' => 'high'], ['plan_costs' => 'Plano de contingencia com reversao em uma hora.']);
    expect(checkFor($rollback, 'contingency')['verdict'])->toBe(ConformanceVerdict::Ok);
});

it('reports only what needs an argument', function () {
    $submission = submissionFor(['cloud' => 'aws'], [
        'standards' => 'Esteira de CI/CD com code review. Logs e tracing. IAM e mTLS.',
    ]);

    $keys = collect(ConformanceChecks::exceptions($submission))->pluck('key');

    expect($keys)->toContain('cloud_target')
        ->and($keys)->not->toContain('observability')
        ->and($keys)->not->toContain('security');
});

it('derives its standards questions from the verdicts, so the two cannot drift', function () {
    // Same signal, two audiences: the table grades it, the checklist asks
    // about it. They used to carry separate copies of the keyword sets.
    $submission = submissionFor(['cloud' => 'aws'], ['standards' => 'Segue o padrão de VPN da companhia.']);

    $exceptions = collect(ConformanceChecks::exceptions($submission))->pluck('key');
    $questions = collect(DeviationRules::for($submission))->pluck('key');

    expect($questions)->toContain('cloud_target')
        ->toContain('observability')
        ->and($exceptions)->toContain('observability');
});

it('records a decision with trackable conditions', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = submissionFor([], ['summary' => 'Texto.'], $user);

    $this->postJson(route('submissions.decision.store', $submission), [
        'status'          => 'approved_with_conditions',
        'decision'        => 'Aprovada, desde que o dimensionamento seja homologado.',
        'conditions_text' => "Homologar o dimensionamento da VM\n\nRestringir a porta 9180 à rede administrativa",
    ])->assertOk();

    $submission = $submission->fresh();

    expect($submission->status)->toBe(SubmissionStatus::ApprovedWithConditions)
        ->and($submission->decided_by_id)->toBe($user->id)
        ->and($submission->decided_at)->not->toBeNull()
        // A condition buried in a paragraph is one nobody follows up on.
        ->and($submission->conditions)->toBe([
            ['text' => 'Homologar o dimensionamento da VM', 'done' => false],
            ['text' => 'Restringir a porta 9180 à rede administrativa', 'done' => false],
        ])
        ->and($submission->openConditions())->toHaveCount(2);
});

it('refuses a deliberation that is not one of the three outcomes', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $this->postJson(route('submissions.decision.store', submissionFor([], [], $user)), [
        'status'   => 'draft',
        'decision' => 'Voltando para rascunho.',
    ])->assertStatus(422)->assertJson(['type' => 'warning']);
});

it('publishes an approved submission into the solution documentation', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $solution = Solution::factory()->create(['name' => 'SkyMob']);
    $submission = Submission::factory()->withSections()->create([
        'name'          => 'SKBridge',
        'solution_id'   => $solution->id,
        'created_by_id' => $user->id,
    ]);
    $submission->section(SubmissionSectionKey::Architecture)->update(['content' => 'VM dedicada na Google Cloud.']);
    $submission->section(SubmissionSectionKey::PlanCosts)->update(['content' => 'Três semanas, R$ 400/mês.']);

    $this->postJson(route('submissions.decision.store', $submission), [
        'status'   => 'approved',
        'decision' => 'Aprovada sem ressalvas.',
    ])->assertOk();

    $page = $solution->notebooks()->first()->pages()->first();

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('CATI — SKBridge')
        ->and($page->documentation)->toContain('VM dedicada na Google Cloud.')
        ->and($page->documentation)->toContain('Aprovada sem ressalvas.')
        // The plan and the costs describe a DECISION, not how the system works
        // — a documentation page carrying them ages badly.
        ->and($page->documentation)->not->toContain('R$ 400/mês')
        ->and($submission->fresh()->promoted_at)->not->toBeNull();
});

it('updates the page it created instead of stacking duplicates', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $solution = Solution::factory()->create();
    $submission = Submission::factory()->withSections()->create([
        'name' => 'SKBridge', 'solution_id' => $solution->id, 'created_by_id' => $user->id,
    ]);
    $submission->section(SubmissionSectionKey::Architecture)->update(['content' => 'Primeira versão.']);

    $this->postJson(route('submissions.decision.store', $submission), ['status' => 'approved', 'decision' => 'ok'])->assertOk();

    $submission->section(SubmissionSectionKey::Architecture)->update(['content' => 'Versão revisada.']);
    $this->postJson(route('submissions.decision.store', $submission), ['status' => 'approved', 'decision' => 'ok'])->assertOk();

    expect(DocumentationPage::count())->toBe(1)
        ->and($solution->notebooks()->first()->pages()->first()->documentation)->toContain('Versão revisada.');
});

it('does not promote a rejected submission, or one with no solution', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $solution = Solution::factory()->create();
    $rejected = Submission::factory()->withSections()->create(['solution_id' => $solution->id, 'created_by_id' => $user->id]);
    $rejected->section(SubmissionSectionKey::Architecture)->update(['content' => 'Texto.']);

    $this->postJson(route('submissions.decision.store', $rejected), [
        'status' => 'rejected', 'decision' => 'Reprovada: falta dimensionamento.',
    ])->assertOk();

    $orphan = submissionFor([], ['architecture' => 'Texto.'], $user);
    $this->postJson(route('submissions.decision.store', $orphan), ['status' => 'approved', 'decision' => 'ok'])->assertOk();

    expect(DocumentationPage::count())->toBe(0)
        ->and($rejected->fresh()->promoted_at)->toBeNull()
        ->and($orphan->fresh()->promoted_at)->toBeNull();
});

it('shows the conformance table and the deliberation on the page', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = submissionFor(['cloud' => 'aws'], ['summary' => 'Texto.'], $user);
    $submission->update([
        'status'     => SubmissionStatus::ApprovedWithConditions,
        'decision'   => 'Aprovada com ressalvas.',
        'conditions' => [['text' => 'Homologar o dimensionamento', 'done' => false]],
        'decided_at' => now(),
    ]);

    $html = $this->get(route('submissions.show', $submission))->assertOk()->getContent();

    expect($html)->toContain('Padrões corporativos')
        ->toContain('Exceção ao padrão')
        ->toContain('Deliberação')
        ->toContain('Homologar o dimensionamento');
});

it('ticks a condition off and reopens it', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = submissionFor([], [], $user);
    $submission->update([
        'decided_at' => now(),
        'decision'   => 'Aprovada com ressalvas.',
        'conditions' => [
            ['text' => 'Homologar o dimensionamento', 'done' => false],
            ['text' => 'Restringir a porta 9180', 'done' => false],
        ],
    ]);

    $response = $this->postJson(route('submissions.conditions.toggle', [$submission, 0]))->assertOk();

    expect($submission->fresh()->conditions[0]['done'])->toBeTrue()
        ->and($submission->fresh()->conditions[1]['done'])->toBeFalse()
        ->and($submission->fresh()->openConditions())->toHaveCount(1)
        ->and(collect($response->json('updatableSlots'))->pluck('id')->all())->toBe(['submission-deliberation-slot']);

    $this->postJson(route('submissions.conditions.toggle', [$submission, 0]))->assertOk();

    expect($submission->fresh()->conditions[0]['done'])->toBeFalse();
});

it('404s on a condition that is not there', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = submissionFor([], [], $user);
    $submission->update(['conditions' => [['text' => 'Uma só', 'done' => false]]]);

    $this->postJson(route('submissions.conditions.toggle', [$submission, 7]))->assertNotFound();
});

it('shows how many conditions are still open', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = submissionFor([], [], $user);
    $submission->update([
        'decided_at' => now(),
        'decision'   => 'Aprovada com ressalvas.',
        'conditions' => [
            ['text' => 'Homologar o dimensionamento', 'done' => true],
            ['text' => 'Restringir a porta 9180', 'done' => false],
        ],
    ]);

    $html = $this->get(route('submissions.show', $submission))->assertOk()->getContent();

    expect($html)->toContain('1 em aberto')
        ->toContain('Homologar o dimensionamento');
});
