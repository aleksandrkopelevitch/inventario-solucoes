<?php

use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Diagram;
use App\Models\Solution;
use App\Support\Documentation\DocumentationRequirements;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function requirementItem(array $requirements, string $key): array
{
    return collect($requirements)->firstWhere('key', $key);
}

it('flags content gaps for a page with no error-handling or contact mention', function () {
    $page = DocumentationPage::factory()->for(Solution::factory()->create(), 'container')->create();

    $requirements = DocumentationRequirements::for($page, 'Uma frase curta.');

    expect(requirementItem($requirements, 'overview')['satisfied'])->toBeFalse()
        ->and(requirementItem($requirements, 'error_handling')['satisfied'])->toBeFalse()
        ->and(requirementItem($requirements, 'contact')['satisfied'])->toBeFalse();
});

it('detects content gaps closing via simple keyword presence', function () {
    $page = DocumentationPage::factory()->for(Solution::factory()->create(), 'container')->create();
    $content = str_repeat('Texto de visão geral suficientemente longo. ', 3)
        . 'Em caso de erro, aciona contingência. O responsável pelo suporte é o time X.';

    $requirements = DocumentationRequirements::for($page, $content);

    expect(requirementItem($requirements, 'overview')['satisfied'])->toBeTrue()
        ->and(requirementItem($requirements, 'error_handling')['satisfied'])->toBeTrue()
        ->and(requirementItem($requirements, 'contact')['satisfied'])->toBeTrue();
});

it('says nothing about a diagram on a page that has none', function () {
    // Not every page documents a flow, so an always-present "tem diagrama" row
    // would report a gap on most pages that have none to have.
    $page = DocumentationPage::factory()->for(Solution::factory()->create(), 'container')->create();

    expect(collect(DocumentationRequirements::for($page))->pluck('key')->all())->not->toContain('diagram');
});

it('flags a linked diagram whose canvas is still empty, and clears once it is drawn', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();
    $diagram = Diagram::factory()->create([
        // One root block is an empty canvas, not a drawing.
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    $page->diagram()->associate($diagram)->save();

    expect(requirementItem(DocumentationRequirements::for($page->fresh()), 'diagram')['satisfied'])->toBeFalse();

    $diagram->update(['chain' => ['nodes' => [
        ['solution_id' => $solution->id, 'label' => null],
        ['solution_id' => null, 'label' => 'ERP externo'],
    ], 'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]]]]);

    expect(requirementItem(DocumentationRequirements::for($page->fresh()), 'diagram')['satisfied'])->toBeTrue();
});

it('reports a solution page\'s hosting attribute as a fact sourced from the Solution record, never a chat question', function () {
    $this->seed(AttributeOptionSeeder::class);
    $solution = Solution::factory()->create(['environment' => 'saas_internal', 'directorate' => null]);
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $requirements = DocumentationRequirements::for($page);

    $environment = requirementItem($requirements, 'environment');
    $directorate = requirementItem($requirements, 'directorate');

    expect($environment['source'])->toBe('attribute')
        ->and($environment['satisfied'])->toBeTrue()
        ->and($environment['value'])->toBe('SaaS interno')
        ->and($directorate['source'])->toBe('attribute')
        ->and($directorate['satisfied'])->toBeFalse();
});

it('returns no requirements for a page under a standalone DocumentationGroup', function () {
    $group = DocumentationGroup::factory()->create();
    $page = DocumentationPage::factory()->for($group, 'container')->create();

    expect(DocumentationRequirements::for($page))->toBe([]);
});
