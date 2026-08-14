<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function solutionFieldAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('updates a single field inline from the detail header, returning that header slot', function () {
    $solution = Solution::factory()->create(['name' => 'Nome antigo']);

    $response = $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['name' => 'Nome novo'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($solution->fresh()->name)->toBe('Nome novo');

    // Only the detail header — there's no solutions-index-slot on this page.
    expect(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toEqual(['solution-detail-header-slot']);
    expect($response->json('updatableSlots.0.content'))->toContain('Nome novo');
});

it('reassigns the vendor from the header select, and clears it back to null', function () {
    $vendor = Company::factory()->create(['name' => 'Fornecedora']);
    $other = Company::factory()->create();
    $solution = Solution::factory()->create(['vendor_company_id' => $other->id]);

    $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['vendor_company_id' => $vendor->id])
        ->assertOk();

    expect($solution->fresh()->vendor_company_id)->toBe($vendor->id);

    $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['vendor_company_id' => null])
        ->assertOk();

    expect($solution->fresh()->vendor_company_id)->toBeNull();
});

it('normalises an emptied text field to null instead of an empty string', function () {
    $solution = Solution::factory()->create([
        'description'            => 'algo',
        'support_operation_note' => 'algo',
    ]);

    $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['description' => ''])
        ->assertOk();

    $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['support_operation_note' => ''])
        ->assertOk();

    $fresh = $solution->fresh();
    expect($fresh->description)->toBeNull();
    expect($fresh->support_operation_note)->toBeNull();
});

it('rejects emptying the name, which is not nullable', function () {
    $solution = Solution::factory()->create(['name' => 'Intacta']);

    $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['name' => null])
        ->assertStatus(422);

    expect($solution->fresh()->name)->toBe('Intacta');
});

it('rejects a vendor that does not exist', function () {
    $solution = Solution::factory()->create(['vendor_company_id' => null]);

    $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['vendor_company_id' => 99999])
        ->assertStatus(422);

    expect($solution->fresh()->vendor_company_id)->toBeNull();
});

it('refuses an attribute column through the field endpoint — those have their own', function () {
    $solution = Solution::factory()->create(['status' => 'active']);

    // `status` isn't in this request's rules, so it's simply not validated data
    // and never reaches the model; `solutions.attributes.update` owns it.
    $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['status' => 'deprecated'])
        ->assertOk();

    expect($solution->fresh()->status)->toBe('active');
});

it('keeps the slug when the name is renamed inline, so the page URL stays valid', function () {
    $solution = Solution::factory()->create(['name' => 'Antiga', 'slug' => 'antiga']);

    $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['name' => 'Renomeada'])
        ->assertOk();

    expect($solution->fresh()->slug)->toBe('antiga');
});

it('forbids a viewer from editing a field inline', function () {
    $solution = Solution::factory()->create(['name' => 'Intacta']);

    $this->actingAs(User::factory()->create())
        ->patchJson(route('solutions.field.update', $solution), ['name' => 'Hackeada'])
        ->assertForbidden();

    expect($solution->fresh()->name)->toBe('Intacta');
});

// ── Logo: the same image-upload tile as the person's photo ────────────────

it('replaces and then removes the logo from the header', function () {
    Storage::fake('public');

    $solution = Solution::factory()->create(['logo_path' => null]);

    $this->actingAs(solutionFieldAdmin())
        ->patch(
            route('solutions.field.update', $solution),
            ['logo'   => UploadedFile::fake()->image('nova.png')],
            ['Accept' => 'application/json'],
        )
        ->assertOk();

    Storage::disk('public')->assertExists($solution->fresh()->logo_path);

    $this->actingAs(solutionFieldAdmin())
        ->patchJson(route('solutions.field.update', $solution), ['logo_action' => 'remove'])
        ->assertOk();

    expect($solution->fresh()->logo_path)->toBeNull();
});

it('also honours "Remover" from the side panel form, where the button was inert', function () {
    Storage::fake('public');
    // The panel validates `category`/`status` against `attribute_options`.
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create([
        'logo_path' => 'solution-logos/antiga.png',
        'category'  => 'erp',
        'status'    => 'active',
    ]);

    $this->actingAs(solutionFieldAdmin())
        ->patch(route('solutions.update', $solution), [
            'name'        => $solution->name,
            'category'    => $solution->category,
            'status'      => $solution->status,
            'logo_action' => 'remove',
        ], ['Accept' => 'application/json'])
        ->assertOk();

    expect($solution->fresh()->logo_path)->toBeNull();
});

// ── What the header actually renders ─────────────────────────────────────

it('makes every own field click-to-edit for an admin, with placeholders for the blank ones', function () {
    $solution = Solution::factory()->create([
        'description'            => null,
        'vendor_company_id'      => null,
        'support_operation_note' => null,
    ]);

    $response = $this->actingAs(solutionFieldAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk();

    foreach (['name', 'description', 'vendor_company_id', 'support_operation_note', 'logo'] as $field) {
        $response->assertSee('data-ak-inline-edit-field="' . $field . '"', false);
    }

    // A blank field still has to be clickable — the placeholder IS the handle.
    $response->assertSeeText('Adicionar descrição');
    $response->assertSeeText('Definir fornecedor');
    $response->assertSeeText('Adicionar nota');
    $response->assertSee('id="solution-logo-inline-file"', false);

    // The 8 attribute badges keep their own, older mechanism on the same header.
    $response->assertSee('data-ak-solution-attribute', false);
});

it('does not dress an empty support note as the red warning box', function () {
    $solution = Solution::factory()->create(['support_operation_note' => null]);

    $this->actingAs(solutionFieldAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSeeText('Suporte × operação')
        ->assertDontSee('border-crit-line', false);

    $solution->update(['support_operation_note' => 'Suporte 8x5, operação 24x7.']);

    $this->actingAs(solutionFieldAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('border-crit-line', false);
});

it('reaches the vendor company through the ↗, leaving the chip to the editor', function () {
    $vendor = Company::factory()->create(['name' => 'Fornecedora']);
    $solution = Solution::factory()->create(['vendor_company_id' => $vendor->id]);

    $response = $this->actingAs(solutionFieldAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk();

    $response->assertSee('href="' . route('companies.show', $vendor) . '" data-ak-inline-edit-link', false);
    // The chip itself is the editor's trigger, not a link.
    $response->assertDontSee('<a href="' . route('companies.show', $vendor) . '" class', false);
});

it('leaves the header read-only for a viewer, with blank fields simply absent', function () {
    $solution = Solution::factory()->create([
        'name'                   => 'Somente leitura',
        'description'            => null,
        'support_operation_note' => null,
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSeeText('Somente leitura');

    $response->assertDontSee('data-ak-inline-edit', false);
    $response->assertDontSeeText('Adicionar descrição');
    $response->assertDontSeeText('Suporte × operação');
});
