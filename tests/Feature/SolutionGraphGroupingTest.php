<?php

use App\Enums\UserRole;
use App\Models\AttributeOption;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use App\Services\SolutionGraphService;
use App\Support\CategoryPalette;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

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
    $solution->people()->attach($second, ['role' => 'owner', 'is_primary' => false]);
    $solution->people()->attach($primary, ['role' => 'owner', 'is_primary' => true]);

    $groups = app(SolutionGraphService::class)->groupedBy('owner')['groups'];

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['label'])->toBe('Ana')
        ->and($groups[0]['solutions'])->toBe(['sol-' . $solution->id]);
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

it('filters the grouped reading by a status a solution can actually have', function () {
    // The two readings filter DIFFERENT things by status: the topology map
    // filters the DIAGRAMS it draws as edges, this one filters the SOLUTIONS.
    // One shared option list made the control lie — "Em desenvolvimento" is a
    // diagram status, so grouped it matched nothing and blanked the map with
    // no message, while `evaluating` was not offered at all.
    Solution::factory()->create(['status' => 'active', 'directorate' => 'Financeiro']);
    Solution::factory()->create(['status' => 'evaluating', 'directorate' => 'Financeiro']);

    $graph = app(SolutionGraphService::class);

    expect($graph->groupedBy('directorate', ['status' => 'evaluating'])['nodes'])->toHaveCount(1)
        ->and($graph->groupedBy('directorate', ['status' => 'active'])['nodes'])->toHaveCount(1)
        ->and($graph->groupedBy('directorate', ['status' => 'all'])['nodes'])->toHaveCount(2);
});

it('offers each reading its own status vocabulary on the filter bar', function () {
    AttributeOption::create(['group' => 'status', 'value' => 'evaluating', 'label' => 'Em avaliação']);

    $this->actingAs(mapReader())->get(route('solutions.map'))
        ->assertOk()
        // The diagram vocabulary, for the topology reading...
        ->assertSee('data-ak-status-for="links"', false)
        ->assertSee('in_development', false)
        // ...and the solution one, for the grouped reading.
        ->assertSee('data-ak-status-for="groups"', false)
        ->assertSee('Em avaliação');
});

it('does not 500 when a filter arrives as an array', function () {
    // Every one of these reaches a `where()`, and `group` also reached a
    // `(string)` cast that answered "Array to string conversion" — a 500 out
    // of a read endpoint any signed-in account can call with `?group[]=x`.
    Solution::factory()->create(['directorate' => 'Financeiro']);

    foreach (['group', 'status', 'category', 'directorate'] as $key) {
        $this->actingAs(mapReader())
            ->getJson(route('solutions.map.data', [$key => ['directorate']]))
            ->assertOk();
    }
});

it('colours a category hub from the category palette, not the rotation', function () {
    // `category` is the only axis with a branch of its own — it is the single
    // call site of CategoryPalette::family() for a hub, while the other three
    // take a colour by position from FAMILIES. It was also the only axis with
    // no test.
    // `solutions.category` is NOT NULL, so the 'Sem categoria' bucket in
    // `bucket()` is unreachable through this column — unlike directorate,
    // which the sibling test covers as a blank.
    AttributeOption::create(['group' => 'category', 'value' => 'erp', 'label' => 'ERP']);
    Solution::factory()->count(2)->create(['category' => 'erp']);

    $graph = app(SolutionGraphService::class)->groupedBy('category', []);
    $labels = collect($graph['groups'])->pluck('label')->all();

    expect($labels)->toContain('ERP')
        ->and(collect($graph['groups'])->firstWhere('label', 'ERP')['count'])->toBe(2)
        ->and(collect($graph['groups'])->firstWhere('label', 'ERP')['family'])
        ->toBe(CategoryPalette::family('erp'));
});

it('loads only the relation the chosen axis actually reads', function () {
    // `DiagramGraphService::solutionNode()` touches no relation — every field
    // it builds is a column or a `*_label` accessor — so the eager loads exist
    // purely for `bucket()`. Loading both on all four axes hydrated a Person
    // plus a pivot model per owner link on the reading the screen opens with.
    $person = Person::factory()->create();
    $company = Company::factory()->create();
    Solution::factory()->count(3)->create([
        'directorate' => 'Financeiro', 'vendor_company_id' => $company->id,
    ])->each(fn ($s) => $s->people()->attach($person, ['role' => 'manager', 'is_primary' => true]));

    $graph = app(SolutionGraphService::class);

    $count = function (string $axis) use ($graph) {
        AttributeOption::forgetCache();
        $graph->groupedBy($axis, []);            // warm the attribute cache first
        DB::flushQueryLog();
        DB::enableQueryLog();
        $graph->groupedBy($axis, []);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // One query for the solutions, and one more only where the bucket needs it.
    expect($count('directorate'))->toBe(1)
        ->and($count('category'))->toBe(1)
        ->and($count('owner'))->toBe(2)
        ->and($count('company'))->toBe(2);
});
