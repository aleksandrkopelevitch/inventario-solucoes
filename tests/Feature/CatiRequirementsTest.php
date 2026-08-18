<?php

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\Submission;
use App\Support\Cati\DeviationRules;
use App\Support\Cati\SubmissionRequirements;
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

it('lists a solution\'s existing integrations as a fact for the legacy impact', function () {
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $integration = Integration::factory()->create(['name' => 'SAP x SkyMob']);
    attachParticipants($integration, [[$solution, 0], [$other, 1]]);

    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);

    expect(fact(SubmissionRequirements::for($submission), 'integrations')['value'])->toContain('SAP x SkyMob')
        ->and(fact(SubmissionRequirements::for($submission), 'integrations')['sections'])->toContain('legacy_impact');
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

    expect(deviation($off, 'cloud_off_target')['question'])->toContain('AWS')
        ->and(deviation($off, 'cloud_off_target')['severity'])->toBe('high')
        ->and(deviation($on, 'cloud_off_target'))->toBeNull();
});

it('spots a contracted solution with no vendor on record', function () {
    $withVendor = catiSubmission(['contract_status' => 'contracted', 'vendor_company_id' => Company::factory()->create()->id]);
    $without = catiSubmission(['contract_status' => 'contracted', 'vendor_company_id' => null]);

    expect(deviation(DeviationRules::for($without), 'vendor_missing'))->not->toBeNull()
        ->and(deviation(DeviationRules::for($withVendor), 'vendor_missing'))->toBeNull();
});

it('names the integrations at stake when the legacy impact is blank', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create(['name' => 'SAP x SkyMob']);
    attachParticipants($integration, [[$solution, 0], [Solution::factory()->create(), 1]]);

    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);

    $rule = deviation(DeviationRules::for($submission), 'legacy_impact_blank');

    expect($rule['question'])->toContain('SAP x SkyMob')
        ->and($rule['severity'])->toBe('high');
});

it('stays quiet about a blank section when nothing known makes the blank surprising', function () {
    // A brand-new submission with no Solution: firing every keyword rule here
    // would bury the useful questions under seven that only repeat "nothing is
    // filled in" — which the section checklist already says.
    $rules = DeviationRules::for(catiSubmission());

    expect(collect($rules)->pluck('key')->all())->toBe(['alternatives_blank']);
});

it('asks about observability and security once the standards section has content', function () {
    $bare = DeviationRules::for(catiSubmission([], ['standards' => 'Segue o SDLC corporativo.']));
    $covered = DeviationRules::for(catiSubmission([], [
        'standards' => 'Segue o SDLC. Logs no Cloud Logging, métricas e tracing. Autenticação via IAM e mTLS.',
    ]));

    expect(deviation($bare, 'observability_absent'))->not->toBeNull()
        ->and(deviation($bare, 'security_absent'))->not->toBeNull()
        ->and(deviation($covered, 'observability_absent'))->toBeNull()
        ->and(deviation($covered, 'security_absent'))->toBeNull();
});

it('asks where the data goes for a saas solution that never mentions it', function () {
    $silent = DeviationRules::for(catiSubmission(['environment' => 'saas'], ['domains_data' => 'Lê pedidos e grava planos de corte.']));
    $covered = DeviationRules::for(catiSubmission(['environment' => 'saas'], ['domains_data' => 'Lê pedidos. Não trafega dado pessoal, sem implicação de LGPD.']));
    $onPremise = DeviationRules::for(catiSubmission(['environment' => 'on_premise'], ['domains_data' => 'Lê pedidos e grava planos de corte.']));

    expect(deviation($silent, 'sensitive_data_unstated'))->not->toBeNull()
        ->and(deviation($covered, 'sensitive_data_unstated'))->toBeNull()
        ->and(deviation($onPremise, 'sensitive_data_unstated'))->toBeNull();
});

it('asks how to roll back a critical solution', function () {
    $silent = DeviationRules::for(catiSubmission(['criticality' => 'critical'], ['plan_costs' => 'Fase 1: provisionamento. Fase 2: piloto.']));
    $covered = DeviationRules::for(catiSubmission(['criticality' => 'critical'], ['plan_costs' => 'Fase 1: provisionamento, com rollback por snapshot.']));
    $low = DeviationRules::for(catiSubmission(['criticality' => 'low'], ['plan_costs' => 'Fase 1: provisionamento. Fase 2: piloto.']));

    expect(deviation($silent, 'contingency_absent'))->not->toBeNull()
        ->and(deviation($covered, 'contingency_absent'))->toBeNull()
        ->and(deviation($low, 'contingency_absent'))->toBeNull();
});

it('asks which platform mediates the integrations, since no column records it', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create();
    attachParticipants($integration, [[$solution, 0], [Solution::factory()->create(), 1]]);

    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);
    $submission->section(SubmissionSectionKey::Architecture)->update(['content' => 'Uma VM na nuvem conversa com a central.']);

    expect(deviation(DeviationRules::for($submission->fresh()), 'integration_platform_unstated'))->not->toBeNull();

    $submission->section(SubmissionSectionKey::Architecture)->update(['content' => 'As integrações transacionais passam pela Digibee.']);

    expect(deviation(DeviationRules::for($submission->fresh()), 'integration_platform_unstated'))->toBeNull();
});

it('always asks what else was considered', function () {
    // Optional on the form, so the section checklist stays quiet — but the
    // committee asks every time.
    $blank = DeviationRules::for(catiSubmission());
    $answered = DeviationRules::for(catiSubmission([], ['alternatives' => 'Avaliamos construir internamente.']));

    expect(deviation($blank, 'alternatives_blank')['severity'])->toBe('low')
        ->and(deviation($answered, 'alternatives_blank'))->toBeNull();
});
