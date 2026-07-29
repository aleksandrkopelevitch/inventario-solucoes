<?php

use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Integration;
use App\Models\Solution;
use App\Support\Documentation\DocumentationRequirements;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function requirementItem(array $requirements, string $key): array
{
    return collect($requirements)->firstWhere('key', $key);
}

it('flags structural gaps for an integration with no topology drawn beyond the root', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create([
        'protocol'  => null,
        'sync_mode' => null,
        'chain'     => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);

    $requirements = DocumentationRequirements::for($integration);

    expect(requirementItem($requirements, 'protocol')['satisfied'])->toBeFalse()
        ->and(requirementItem($requirements, 'sync_mode')['satisfied'])->toBeFalse()
        ->and(requirementItem($requirements, 'participants')['satisfied'])->toBeFalse()
        ->and(requirementItem($requirements, 'diagram')['satisfied'])->toBeFalse();
});

it('marks an integration\'s structural requirements satisfied once fields and topology are set', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();
    $integration = Integration::factory()->create();
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $requirements = DocumentationRequirements::for($integration->fresh());

    expect(requirementItem($requirements, 'protocol')['satisfied'])->toBeTrue()
        ->and(requirementItem($requirements, 'participants')['satisfied'])->toBeTrue()
        ->and(requirementItem($requirements, 'diagram')['satisfied'])->toBeTrue();
});

it('flags content gaps for an integration doc with no error-handling or contact mention', function () {
    $integration = Integration::factory()->create();

    $requirements = DocumentationRequirements::for($integration, 'Uma frase curta.');

    expect(requirementItem($requirements, 'overview')['satisfied'])->toBeFalse()
        ->and(requirementItem($requirements, 'error_handling')['satisfied'])->toBeFalse()
        ->and(requirementItem($requirements, 'contact')['satisfied'])->toBeFalse();
});

it('detects content gaps closing via simple keyword presence', function () {
    $integration = Integration::factory()->create();
    $content = str_repeat('Texto de visão geral suficientemente longo. ', 3)
        . 'Em caso de erro, aciona contingência. O responsável pelo suporte é o time X.';

    $requirements = DocumentationRequirements::for($integration, $content);

    expect(requirementItem($requirements, 'overview')['satisfied'])->toBeTrue()
        ->and(requirementItem($requirements, 'error_handling')['satisfied'])->toBeTrue()
        ->and(requirementItem($requirements, 'contact')['satisfied'])->toBeTrue();
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
