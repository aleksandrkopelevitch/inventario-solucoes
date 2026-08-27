<?php

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Support\Documentation\DocumentationRequirements;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function requirementItem(array $requirements, string $key): array
{
    return collect($requirements)->firstWhere('key', $key);
}

/** A page in a caderno that documents exactly one solution. */
function pageOfSolution(Solution $solution): DocumentationPage
{
    $notebook = Notebook::factory()->create();
    $notebook->solutions()->attach($solution);

    return DocumentationPage::factory()->for($notebook)->create();
}

it('flags content gaps for a page with no error-handling or contact mention', function () {
    $page = pageOfSolution(Solution::factory()->create());

    $requirements = DocumentationRequirements::for($page, 'Uma frase curta.');

    expect(requirementItem($requirements, 'overview')['satisfied'])->toBeFalse()
        ->and(requirementItem($requirements, 'error_handling')['satisfied'])->toBeFalse()
        ->and(requirementItem($requirements, 'contact')['satisfied'])->toBeFalse();
});

it('detects content gaps closing via simple keyword presence', function () {
    $page = pageOfSolution(Solution::factory()->create());
    $content = str_repeat('Texto de visão geral suficientemente longo. ', 3)
        . 'Em caso de erro, aciona contingência. O responsável pelo suporte é o time X.';

    $requirements = DocumentationRequirements::for($page, $content);

    expect(requirementItem($requirements, 'overview')['satisfied'])->toBeTrue()
        ->and(requirementItem($requirements, 'error_handling')['satisfied'])->toBeTrue()
        ->and(requirementItem($requirements, 'contact')['satisfied'])->toBeTrue();
});

it('says nothing about diagrams at all', function () {
    // The checklist had a "drawing is actually drawn" row read off the page's
    // `diagram_id`. A page cites drawings in its text now, so there is no one
    // drawing to check — and auditing every cited one would put this checklist
    // in the business of grading other records.
    $page = pageOfSolution(Solution::factory()->create());

    expect(collect(DocumentationRequirements::for($page))->pluck('key')->all())->not->toContain('diagram');
});

it('reports the hosting attribute of a linked solution as a fact, never a chat question', function () {
    $this->seed(AttributeOptionSeeder::class);
    $solution = Solution::factory()->create(['environment' => 'saas_internal', 'directorate' => null]);
    $page = pageOfSolution($solution);

    $requirements = DocumentationRequirements::for($page);

    $environment = requirementItem($requirements, 'environment');
    $directorate = requirementItem($requirements, 'directorate');

    expect($environment['source'])->toBe('attribute')
        ->and($environment['satisfied'])->toBeTrue()
        ->and($environment['value'])->toBe('SaaS interno')
        ->and($directorate['source'])->toBe('attribute')
        ->and($directorate['satisfied'])->toBeFalse();
});

it('reports only content checks for a page whose caderno documents no solution', function () {
    // The "quando for o caso" rule: with no solution behind it there is no
    // record to pull attribute facts from, and inventing gaps would be worse
    // than reporting none.
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    expect(collect(DocumentationRequirements::for($page))->pluck('source')->unique()->all())
        ->toBe(['content']);
});

it('qualifies each attribute fact with its own solution when a caderno documents several', function () {
    // A fact printed without saying whose it is, is worse than no fact: two
    // systems can legitimately disagree about hosting, and the checklist feeds
    // the model's prompt.
    $this->seed(AttributeOptionSeeder::class);
    $notebook = Notebook::factory()->create();
    $notebook->solutions()->attach(Solution::factory()->create(['name' => 'GCP', 'environment' => 'saas']));
    $notebook->solutions()->attach(Solution::factory()->create(['name' => 'SAP ECC', 'environment' => 'on_premises']));
    $page = DocumentationPage::factory()->for($notebook)->create();

    $labels = collect(DocumentationRequirements::for($page))
        ->where('source', 'attribute')
        ->pluck('label');

    expect($labels)->toContain('Hospedagem · GCP')
        ->and($labels)->toContain('Hospedagem · SAP ECC')
        // Six attribute rows, three per solution — and each row keeps a key of
        // its own, or the second solution's would overwrite the first's.
        ->and($labels)->toHaveCount(6);
});
