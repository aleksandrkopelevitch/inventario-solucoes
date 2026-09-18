<?php

use App\Enums\UserRole;
use App\Models\AttributeOption;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use App\Services\SolutionGraphService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function mapReader(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('groups the catalog by directorate', function () {
    AttributeOption::create(['group' => 'directorate', 'value' => 'ti', 'label' => 'TI']);
    Solution::factory()->count(2)->create(['directorate' => 'ti']);
    Solution::factory()->create(['directorate' => null]);

    $graph = app(SolutionGraphService::class)->groupedBy('directorate');

    expect($graph['nodes'])->toHaveCount(3)
        ->and($graph['edges'])->toBeEmpty()
        ->and($graph['groupAxis'])->toBe('directorate')
        ->and(collect($graph['groups'])->pluck('count', 'label')->all())
        ->toBe(['TI' => 2, 'Sem diretoria' => 1]);
});

it('files a solution under its primary owner', function () {
    $primary = Person::factory()->create(['name' => 'Ana']);
    $second = Person::factory()->create(['name' => 'Bruno']);
    $solution = Solution::factory()->create();
    $solution->people()->attach($second, ['role' => 'technical', 'is_primary' => false]);
    $solution->people()->attach($primary, ['role' => 'technical', 'is_primary' => true]);

    $groups = app(SolutionGraphService::class)->groupedBy('owner')['groups'];

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['label'])->toBe('Ana')
        ->and($groups[0]['solutions'])->toBe(['sol-' . $solution->id]);
});

it('files two solutions the same way when nobody is primary', function () {
    // The normal case, not the exotic one: `attachPerson` never writes
    // `is_primary`, so most solutions carry several or none — and the pick used
    // to be whichever row the database returned first, which could move a
    // solution between hubs across two page loads.
    $ana = Person::factory()->create(['name' => 'Ana']);
    $bruno = Person::factory()->create(['name' => 'Bruno']);

    $first = Solution::factory()->create();
    $first->people()->attach($ana, ['role' => 'technical']);
    $first->people()->attach($bruno, ['role' => 'manager']);

    $second = Solution::factory()->create();
    $second->people()->attach($bruno, ['role' => 'manager']);
    $second->people()->attach($ana, ['role' => 'technical']);

    $groups = collect(app(SolutionGraphService::class)->groupedBy('owner')['groups']);

    // A manager answers for a system before a technical contact does, whichever
    // order the links were made in.
    expect($groups)->toHaveCount(1)
        ->and($groups->first()['label'])->toBe('Bruno')
        ->and($groups->first()['count'])->toBe(2);
});

it('does not make a vendor contact the responsible one', function () {
    // They work for the supplier. A screen titled "Responsável" naming them
    // would say something false about who to ask inside Leo.
    $solution = Solution::factory()->create();
    $solution->people()->attach(Person::factory()->create(['name' => 'Contato Fornecedor']), [
        'role' => 'vendor_contact', 'is_primary' => true,
    ]);

    $groups = app(SolutionGraphService::class)->groupedBy('owner')['groups'];

    expect($groups)->toHaveCount(1)->and($groups[0]['label'])->toBe('Sem responsável');
});

it('answers the grouped reading with the solution status vocabulary', function () {
    // A diagram's status and a solution's share three values and disagree on
    // two. "Em desenvolvimento" is a diagram status and matches no solution;
    // "Em avaliação" is a solution status the topology reading cannot ask for.
    Solution::factory()->create(['status' => 'evaluating', 'directorate' => null]);

    $service = app(SolutionGraphService::class);

    expect($service->groupedBy('directorate', ['status' => 'evaluating'])['groups'])->toHaveCount(1)
        ->and($service->groupedBy('directorate', ['status' => 'in_development'])['groups'])->toBeEmpty()
        ->and($service->groupedBy('directorate', ['status' => 'in_development'])['groupAxis'])->toBe('directorate');
});

it('applies a filter alongside an axis', function () {
    AttributeOption::create(['group' => 'directorate', 'value' => 'ti', 'label' => 'TI']);
    Solution::factory()->create(['directorate' => 'ti']);
    Solution::factory()->create(['directorate' => null]);

    $graph = app(SolutionGraphService::class)->groupedBy('owner', ['directorate' => 'ti']);

    expect($graph['nodes'])->toHaveCount(1)
        ->and(collect($graph['groups'])->sum('count'))->toBe(1);
});

it('keeps the ones with nothing to group by instead of dropping them', function () {
    Solution::factory()->create(['vendor_company_id' => Company::factory()->create(['name' => 'Acme'])->id]);
    Solution::factory()->count(3)->create(['vendor_company_id' => null]);

    $groups = collect(app(SolutionGraphService::class)->groupedBy('company')['groups']);

    // Biggest first, and the blank bucket is a bucket: "3 systems with no
    // vendor" is the useful half of this reading.
    expect($groups->pluck('label')->all())->toBe(['Sem fornecedor', 'Acme'])
        ->and($groups->sum('count'))->toBe(4);
});

it('falls back to the topology map when no axis is asked for', function () {
    Solution::factory()->create();

    $this->actingAs(mapReader())
        ->getJson(route('solutions.map.data'))
        ->assertOk()
        ->assertJsonMissingPath('groups');
});

it('serves the grouped reading from the same endpoint', function () {
    Solution::factory()->count(2)->create(['directorate' => null]);

    $this->actingAs(mapReader())
        ->getJson(route('solutions.map.data', ['group' => 'owner']))
        ->assertOk()
        ->assertJsonPath('groupAxis', 'owner')
        ->assertJsonPath('groups.0.count', 2)
        ->assertJsonPath('edges', []);
});

it('ignores an axis nobody defined rather than failing', function () {
    Solution::factory()->create();

    expect(app(SolutionGraphService::class)->groupedBy('inventado')['groupAxis'])->toBe('directorate');
});
