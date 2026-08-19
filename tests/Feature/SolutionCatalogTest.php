<?php

use App\Enums\UserRole;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function admin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('renders the catalog page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('solutions.index'))
        ->assertOk()
        ->assertSee('Soluções');
});

it('returns the filtered index slot as JSON', function () {
    Solution::factory()->create(['name' => 'Alpha ERP', 'category' => 'erp']);
    Solution::factory()->create(['name' => 'Beta CRM', 'category' => 'crm']);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('solutions.index', ['filter' => ['category' => 'erp']]))
        ->assertOk()
        ->assertJsonStructure(['updatableSlots' => [['id', 'content']]]);

    $content = $response->json('updatableSlots.0.content');
    expect($content)->toContain('Alpha ERP')
        ->and($content)->not->toContain('Beta CRM');
});

it('returns search results in the index slot as JSON', function () {
    Solution::factory()->create(['name' => 'Gamma Payments']);
    Solution::factory()->create(['name' => 'Delta Logística']);

    $content = $this->actingAs(User::factory()->create())
        ->getJson(route('solutions.index', ['filter' => ['search' => 'Gamma']]))
        ->assertOk()
        ->json('updatableSlots.0.content');

    // The matched term is wrapped in <mark> (search highlighting), so "Gamma"
    // and "Payments" no longer sit in one contiguous run — assert both parts.
    expect($content)->toContain('Gamma')
        ->and($content)->toContain('Payments')
        ->and($content)->not->toContain('Delta Logística');
});

it('lets an admin create a solution', function () {
    $this->seed(AttributeOptionSeeder::class);

    $this->actingAs(admin())
        ->postJson(route('solutions.store'), [
            'name'     => 'Novo Sistema',
            'category' => 'erp',
            'status'   => 'active',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $this->assertDatabaseHas('solutions', ['name' => 'Novo Sistema', 'slug' => 'novo-sistema']);
});

it('lets an admin update a solution', function () {
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create(['name' => 'Antigo', 'category' => 'erp', 'status' => 'active']);

    $this->actingAs(admin())
        ->patchJson(route('solutions.update', $solution), [
            'name'     => 'Renomeado',
            'category' => $solution->category,
            'status'   => $solution->status,
        ])
        ->assertOk();

    $this->assertDatabaseHas('solutions', ['id' => $solution->id, 'name' => 'Renomeado']);
});

it('preserves the active filter when updating a solution refreshes the index slot', function () {
    // Regression coverage for the "preserving filters when a mutation
    // refreshes a filtered index slot" chain (AGENTS.md) — editing a
    // Solution while filtered must not silently reset the visible list to
    // everything, even though the store()/update() wiring already does this
    // correctly (this just proves it end-to-end).
    $this->seed(AttributeOptionSeeder::class);

    $matching = Solution::factory()->create(['name' => 'Alpha ERP', 'category' => 'erp', 'status' => 'active']);
    Solution::factory()->create(['name' => 'Beta CRM', 'category' => 'crm', 'status' => 'active']);

    $response = $this->actingAs(admin())
        ->patchJson(route('solutions.update', ['solution' => $matching, 'filter' => ['category' => 'erp']]), [
            'name'     => 'Alpha ERP Renomeado',
            'category' => 'erp',
            'status'   => 'active',
        ])
        ->assertOk();

    $indexSlot = collect($response->json('updatableSlots'))->firstWhere('id', 'solutions-index-slot');
    expect($indexSlot['content'])->toContain('Alpha ERP Renomeado')
        ->and($indexSlot['content'])->not->toContain('Beta CRM');
});

it('forbids a viewer from creating a solution', function () {
    $this->actingAs(User::factory()->create()) // viewer
        ->postJson(route('solutions.store'), ['name' => 'X', 'category' => 'erp', 'status' => 'active'])
        ->assertForbidden();
});
