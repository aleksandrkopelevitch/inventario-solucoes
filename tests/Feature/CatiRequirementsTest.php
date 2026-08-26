<?php

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Enums\SubmissionStatus;
use App\Models\Company;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionSource;
use App\Support\Cati\DeviationRules;
use App\Support\Cati\SubmissionRequirements;
use App\Support\Cati\SubmissionStages;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function catiSubmission(array $solutionAttributes = [], array $sections = []): Submission
{
    $solution = $solutionAttributes === [] ? null : Solution::factory()->create($solutionAttributes);

    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution?->id]);

    foreach ($sections as $key => $content) {
        $submission->section(SubmissionSectionKey::from($key))->update([
            'content' => $content,
            'state'   => SubmissionSectionState::Confirmed,
        ]);
    }

    return $submission->fresh();
}

function fact(array $requirements, string $key): ?array
{
    return collect($requirements['facts'])->firstWhere('key', $key);
}

function deviation(array $rules, string $key): ?array
{
    return collect($rules)->firstWhere('key', $key);
}

it('reports what the catalog knows as facts, never as questions', function () {
    $this->seed(AttributeOptionSeeder::class);

    $vendor = Company::factory()->create(['name' => 'SkyMob']);
    $submission = catiSubmission([
        'category'          => 'manufacturing',
        'cloud'             => 'gcp',
        'criticality'       => 'high',
        'environment'       => 'saas',
        'vendor_company_id' => $vendor->id,
    ]);

    $requirements = SubmissionRequirements::for($submission);

    // Everything here is already in the record — asking an architect which
    // cloud their own system runs on is exactly the friction being removed.
    expect(fact($requirements, 'cloud')['value'])->toBe('GCP')
        ->and(fact($requirements, 'criticality'))->not->toBeNull()
        ->and(fact($requirements, 'vendor')['value'])->toBe('SkyMob')
        ->and(fact($requirements, 'vendor')['sections'])->toContain('plan_costs');
});

it('leaves a blank column out of the facts', function () {
    // A null column is not knowledge — it is a gap the interview may ask about.
    $submission = catiSubmission(['cloud' => null, 'category' => 'manufacturing']);

    expect(fact(SubmissionRequirements::for($submission), 'cloud'))->toBeNull()
        ->and(fact(SubmissionRequirements::for($submission), 'category'))->not->toBeNull();
});

it('has no facts at all when the submission is not tied to a solution', function () {
    $requirements = SubmissionRequirements::for(catiSubmission());

    expect($requirements['facts'])->toBe([])
        ->and(collect($requirements['structural'])->firstWhere('key', 'solution')['satisfied'])->toBeFalse();
});

it('lists a solution\'s existing diagrams as a fact for the legacy impact', function () {
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $diagram = Diagram::factory()->create(['name' => 'SAP x SkyMob']);
    attachParticipants($diagram, [[$solution, 0], [$other, 1]]);

    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);

    expect(fact(SubmissionRequirements::for($submission), 'diagrams')['value'])->toContain('SAP x SkyMob')
        ->and(fact(SubmissionRequirements::for($submission), 'diagrams')['sections'])->toContain('legacy_impact');
});

it('tells the truth about a section emptied after being confirmed', function () {
    $submission = catiSubmission([], ['summary' => 'Texto.']);
    $submission->section(SubmissionSectionKey::Summary)->update(['content' => '']);

    $summary = collect(SubmissionRequirements::for($submission->fresh())['sections'])->firstWhere('key', 'summary');

    // `answered` reads the content, not the state: the committee reads content.
    expect($summary['answered'])->toBeFalse()
        ->and($summary['state'])->toBe('confirmed');
});

it('counts only the six mandatory sections as blocking', function () {
    $submission = catiSubmission([], [
        'summary'      => 'Resumo.',
        'architecture' => 'Arquitetura.',
    ]);

    expect(SubmissionRequirements::missingMandatory($submission))
        ->toBe(['benefits_risks', 'legacy_impact', 'standards', 'plan_costs']);
});

it('asks about the target cloud only when the solution is off it', function () {
    $off = DeviationRules::for(catiSubmission(['cloud' => 'aws']));
    $on = DeviationRules::for(catiSubmission(['cloud' => 'gcp']));

    expect(deviation($off, 'cloud_target')['question'])->toContain('AWS')
        ->and(deviation($off, 'cloud_target')['severity'])->toBe('high')
        ->and(deviation($on, 'cloud_target'))->toBeNull();
});

it('spots a contracted solution with no vendor on record', function () {
    $withVendor = catiSubmission(['contract_status' => 'contracted', 'vendor_company_id' => Company::factory()->create()->id]);
    $without = catiSubmission(['contract_status' => 'contracted', 'vendor_company_id' => null]);

    expect(deviation(DeviationRules::for($without), 'vendor_missing'))->not->toBeNull()
        ->and(deviation(DeviationRules::for($withVendor), 'vendor_missing'))->toBeNull();
});

it('names the diagrams at stake when the legacy impact is blank', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create(['name' => 'SAP x SkyMob']);
    attachParticipants($diagram, [[$solution, 0], [Solution::factory()->create(), 1]]);

    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);

    $rule = deviation(DeviationRules::for($submission), 'legacy_impact_blank');

    expect($rule['question'])->toContain('SAP x SkyMob')
        ->and($rule['severity'])->toBe('high');
});

it('stays quiet about a blank section when nothing known makes the blank surprising', function () {
    // A brand-new submission with no Solution. The keyword-driven rules
    // (observability, security, SDLC, contingency…) must NOT fire: they would
    // bury the useful questions under a restatement of "nothing is filled in",
    // which the section checklist already says.
    //
    // The two that do fire are the two that are genuinely worth asking with an
    // empty record — where it runs (nothing else surfaces an unknown cloud;
    // the facts list only shows columns that ARE set), and what else was
    // considered.
    $rules = DeviationRules::for(catiSubmission());

    expect(collect($rules)->pluck('key')->all())->toBe(['cloud_target', 'alternatives_blank'])
        ->and(collect($rules)->firstWhere('key', 'cloud_target')['question'])->toContain('Em qual nuvem');
});

it('asks about observability and security once the standards section has content', function () {
    $bare = DeviationRules::for(catiSubmission([], ['standards' => 'Segue o SDLC corporativo.']));
    $covered = DeviationRules::for(catiSubmission([], [
        'standards' => 'Segue o SDLC. Logs no Cloud Logging, métricas e tracing. Autenticação via IAM e mTLS.',
    ]));

    expect(deviation($bare, 'observability'))->not->toBeNull()
        ->and(deviation($bare, 'security'))->not->toBeNull()
        ->and(deviation($covered, 'observability'))->toBeNull()
        ->and(deviation($covered, 'security'))->toBeNull();
});

it('asks where the data goes for a saas solution that never mentions it', function () {
    $silent = DeviationRules::for(catiSubmission(['environment' => 'saas'], ['domains_data' => 'Lê pedidos e grava planos de corte.']));
    $covered = DeviationRules::for(catiSubmission(['environment' => 'saas'], ['domains_data' => 'Lê pedidos. Não trafega dado pessoal, sem implicação de LGPD.']));
    $onPremise = DeviationRules::for(catiSubmission(['environment' => 'on_premise'], ['domains_data' => 'Lê pedidos e grava planos de corte.']));

    expect(deviation($silent, 'sensitive_data'))->not->toBeNull()
        ->and(deviation($covered, 'sensitive_data'))->toBeNull()
        ->and(deviation($onPremise, 'sensitive_data'))->toBeNull();
});

it('asks how to roll back a critical solution', function () {
    $silent = DeviationRules::for(catiSubmission(['criticality' => 'critical'], ['plan_costs' => 'Fase 1: provisionamento. Fase 2: piloto.']));
    $covered = DeviationRules::for(catiSubmission(['criticality' => 'critical'], ['plan_costs' => 'Fase 1: provisionamento, com rollback por snapshot.']));
    $low = DeviationRules::for(catiSubmission(['criticality' => 'low'], ['plan_costs' => 'Fase 1: provisionamento. Fase 2: piloto.']));

    expect(deviation($silent, 'contingency'))->not->toBeNull()
        ->and(deviation($covered, 'contingency'))->toBeNull()
        ->and(deviation($low, 'contingency'))->toBeNull();
});

it('asks which platform mediates the diagrams, since no column records it', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create();
    attachParticipants($diagram, [[$solution, 0], [Solution::factory()->create(), 1]]);

    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);
    $submission->section(SubmissionSectionKey::Architecture)->update(['content' => 'Uma VM na nuvem conversa com a central.']);

    expect(deviation(DeviationRules::for($submission->fresh()), 'integration_platform'))->not->toBeNull();

    $submission->section(SubmissionSectionKey::Architecture)->update(['content' => 'As integrações transacionais passam pela Digibee.']);

    expect(deviation(DeviationRules::for($submission->fresh()), 'integration_platform'))->toBeNull();
});

it('always asks what else was considered', function () {
    // Optional on the form, so the section checklist stays quiet — but the
    // committee asks every time.
    $blank = DeviationRules::for(catiSubmission());
    $answered = DeviationRules::for(catiSubmission([], ['alternatives' => 'Avaliamos construir internamente.']));

    expect(deviation($blank, 'alternatives_blank')['severity'])->toBe('low')
        ->and(deviation($answered, 'alternatives_blank'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Stages — the workbench's forward motion (App\Support\Cati\SubmissionStages)
|--------------------------------------------------------------------------
*/

function stage(array $stages, string $key): array
{
    return collect($stages)->firstWhere('key', $key);
}

it('opens a brand-new submission at the material stage', function () {
    $stages = SubmissionStages::for(catiSubmission());

    expect(stage($stages, 'material')['state'])->toBe(SubmissionStages::CURRENT)
        ->and(stage($stages, 'interview')['state'])->toBe(SubmissionStages::PENDING)
        ->and(stage($stages, 'committee')['state'])->toBe(SubmissionStages::PENDING);
});

it('never marks a later stage current just because it happens to be satisfied', function () {
    // Material attached, sections empty. "Revisão" is vacuously unfinished and
    // "Comitê" undecided — the person is at the interview, and pointing them
    // at the review would send them to confirm text that does not exist.
    $submission = catiSubmission();
    SubmissionSource::factory()->create(['submission_id' => $submission->id]);

    $stages = SubmissionStages::for($submission->fresh());

    expect(stage($stages, 'material')['state'])->toBe(SubmissionStages::DONE)
        ->and(stage($stages, 'interview')['state'])->toBe(SubmissionStages::CURRENT)
        ->and(stage($stages, 'review')['state'])->toBe(SubmissionStages::PENDING);
});

it('moves the pointer past a skipped stage instead of stranding on it', function () {
    // Attaching material is optional — someone can just answer the questions.
    // With "current = first unfinished", the strip would still read "Material"
    // on a submission whose document is written.
    $submission = catiSubmission();

    foreach (SubmissionSectionKey::mandatoryCases() as $key) {
        $submission->section($key)->update(['content' => 'Texto.', 'state' => SubmissionSectionState::Drafted]);
    }

    $stages = SubmissionStages::for($submission->fresh());

    expect(stage($stages, 'material')['state'])->toBe(SubmissionStages::PENDING)
        ->and(stage($stages, 'interview')['state'])->toBe(SubmissionStages::DONE)
        ->and(stage($stages, 'review')['state'])->toBe(SubmissionStages::CURRENT);
});

it('finishes the interview stage on content and the review stage on confirmation', function () {
    $submission = catiSubmission();
    SubmissionSource::factory()->create(['submission_id' => $submission->id]);

    // Written by the assistant and applied — content, but nobody signed it.
    foreach (SubmissionSectionKey::mandatoryCases() as $key) {
        $submission->section($key)->update([
            'content' => 'Texto.',
            'state'   => SubmissionSectionState::Drafted,
        ]);
    }

    $stages = SubmissionStages::for($submission->fresh());

    expect(stage($stages, 'interview')['state'])->toBe(SubmissionStages::DONE)
        ->and(stage($stages, 'review')['state'])->toBe(SubmissionStages::CURRENT);

    foreach (SubmissionSectionKey::mandatoryCases() as $key) {
        $submission->section($key)->update(['state' => SubmissionSectionState::Confirmed]);
    }

    expect(stage(SubmissionStages::for($submission->fresh()), 'review')['state'])->toBe(SubmissionStages::DONE);
});

it('closes the committee stage only once the record carries a real outcome', function () {
    $submitted = catiSubmission();
    $submitted->update(['status' => SubmissionStatus::Submitted]);

    expect(stage(SubmissionStages::for($submitted->fresh()), 'committee')['state'])->not->toBe(SubmissionStages::DONE);

    $submitted->update(['status' => SubmissionStatus::ApprovedWithConditions]);

    expect(stage(SubmissionStages::for($submitted->fresh()), 'committee')['state'])->toBe(SubmissionStages::DONE);
});

it('counts progress over all eleven sections, not just the mandatory six', function () {
    // A bar reading 6/6 while five deck slides are blank is the kind of "done"
    // that only shows up in the meeting.
    $submission = catiSubmission();

    foreach (SubmissionSectionKey::mandatoryCases() as $key) {
        $submission->section($key)->update(['content' => 'Texto.', 'state' => SubmissionSectionState::Confirmed]);
    }

    $progress = SubmissionStages::progress($submission->fresh());

    expect($progress['total'])->toBe(count(SubmissionSectionKey::cases()))
        ->and($progress['answered'])->toBe(6)
        ->and($progress['confirmed'])->toBe(6)
        ->and($progress['percent'])->toBeLessThan(100);
});

it('counts a section as answered but unconfirmed while it is still a draft', function () {
    $submission = catiSubmission();
    $submission->section(SubmissionSectionKey::Summary)->update([
        'content' => 'Texto.',
        'state'   => SubmissionSectionState::Drafted,
    ]);

    $progress = SubmissionStages::progress($submission->fresh());

    expect($progress['answered'])->toBe(1)->and($progress['confirmed'])->toBe(0);
});
