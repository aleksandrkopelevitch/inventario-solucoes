<?php

use App\Enums\CompanyKind;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function companyFieldAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('updates a single field inline from the detail header, returning that header slot', function () {
    $company = Company::factory()->create(['name' => 'Nome antigo']);

    $response = $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['name' => 'Nome novo'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($company->fresh()->name)->toBe('Nome novo');

    // Only the detail header — there's no companies-index-slot on this page.
    expect(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toEqual(['company-detail-header-slot']);
    expect($response->json('updatableSlots.0.content'))->toContain('Nome novo');
});

it('changes the company kind from the header select', function () {
    $company = Company::factory()->create(['kind' => CompanyKind::Vendor->value]);

    $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['kind' => CompanyKind::Partner->value])
        ->assertOk();

    expect($company->fresh()->kind)->toBe(CompanyKind::Partner);
});

it('normalises an emptied nullable field to null instead of an empty string', function () {
    $company = Company::factory()->create(['website' => 'https://antigo.com', 'notes' => 'algo']);

    $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['website' => null])
        ->assertOk();

    $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['notes' => ''])
        ->assertOk();

    $fresh = $company->fresh();
    expect($fresh->website)->toBeNull();
    expect($fresh->notes)->toBeNull();
});

it('rejects emptying the name or the kind, which are not nullable', function () {
    $company = Company::factory()->create(['name' => 'Leo Madeiras']);

    $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['name' => null])
        ->assertStatus(422);

    $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['kind' => null])
        ->assertStatus(422);

    expect($company->fresh()->name)->toBe('Leo Madeiras');
});

it('rejects an invalid website or an unknown kind', function () {
    $company = Company::factory()->create(['website' => 'https://ok.com']);

    $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['website' => 'nao-e-url'])
        ->assertStatus(422);

    $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['kind' => 'fornecedora-suprema'])
        ->assertStatus(422);

    expect($company->fresh()->website)->toBe('https://ok.com');
});

it('keeps the slug when the name is renamed inline, so the page URL stays valid', function () {
    $company = Company::factory()->create(['name' => 'Antiga', 'slug' => 'antiga']);

    $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['name' => 'Renomeada'])
        ->assertOk();

    expect($company->fresh()->slug)->toBe('antiga');
});

it('forbids a viewer from editing a field inline', function () {
    $company = Company::factory()->create(['name' => 'Intacta']);

    $this->actingAs(User::factory()->create())
        ->patchJson(route('companies.field.update', $company), ['name' => 'Hackeada'])
        ->assertForbidden();

    expect($company->fresh()->name)->toBe('Intacta');
});

// ── Logo: the same image-upload tile as the person's photo ────────────────

it('replaces and then removes the logo from the header', function () {
    Storage::fake('public');

    $company = Company::factory()->create(['logo_path' => null]);

    $this->actingAs(companyFieldAdmin())
        ->patch(
            route('companies.field.update', $company),
            ['logo'   => UploadedFile::fake()->image('nova.png')],
            ['Accept' => 'application/json'],
        )
        ->assertOk();

    $stored = $company->fresh()->logo_path;
    Storage::disk('public')->assertExists($stored);

    $this->actingAs(companyFieldAdmin())
        ->patchJson(route('companies.field.update', $company), ['logo_action' => 'remove'])
        ->assertOk();

    expect($company->fresh()->logo_path)->toBeNull();
});

it('also honours "Remover" from the side panel form, where the button was inert', function () {
    Storage::fake('public');

    $company = Company::factory()->create(['logo_path' => 'company-logos/antiga.png']);

    $this->actingAs(companyFieldAdmin())
        ->patch(route('companies.update', $company), [
            'name'        => $company->name,
            'kind'        => $company->kind->value,
            'logo_action' => 'remove',
        ], ['Accept' => 'application/json'])
        ->assertOk();

    expect($company->fresh()->logo_path)->toBeNull();
});

// ── What the header actually renders ─────────────────────────────────────

it('makes every datum click-to-edit for an admin, with placeholders for the blank ones', function () {
    $company = Company::factory()->create(['website' => null, 'notes' => null]);

    $response = $this->actingAs(companyFieldAdmin())
        ->get(route('companies.show', $company))
        ->assertOk();

    foreach (['name', 'kind', 'website', 'notes', 'logo'] as $field) {
        $response->assertSee('data-ak-inline-edit-field="' . $field . '"', false);
    }

    // A blank field still has to be clickable — the placeholder IS the handle.
    $response->assertSeeText('Adicionar site');
    $response->assertSeeText('Adicionar anotações');
    // The logo tile gets its own element ids so it can't fight the panel's.
    $response->assertSee('id="company-logo-inline-file"', false);
});

it('reaches the website through the ↗, in a new tab, leaving the text to the editor', function () {
    $company = Company::factory()->create(['website' => 'https://leomadeiras.com.br']);

    $response = $this->actingAs(companyFieldAdmin())
        ->get(route('companies.show', $company))
        ->assertOk();

    $response->assertSee('href="https://leomadeiras.com.br" data-ak-inline-edit-link', false);
    $response->assertSee('target="_blank"', false);
    // The URL text itself is the editor's trigger, not a link.
    $response->assertDontSee('<a href="https://leomadeiras.com.br" class', false);
});

it('leaves the header read-only for a viewer, with blank fields simply absent', function () {
    $company = Company::factory()->create(['name' => 'Somente leitura', 'website' => null, 'notes' => null]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('companies.show', $company))
        ->assertOk()
        ->assertSeeText('Somente leitura');

    $response->assertDontSee('data-ak-inline-edit', false);
    $response->assertDontSeeText('Adicionar site');
    $response->assertDontSeeText('Adicionar anotações');
});
