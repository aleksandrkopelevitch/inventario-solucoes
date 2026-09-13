<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use App\Rules\AsciiSlug;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

/**
 * One rule for every slug in the app: lowercase ASCII letters, digits and
 * single hyphens. A slug is an ADDRESS — it travels through route binding, is
 * compared byte-for-byte where authorisation depends on it, and gets pasted
 * into documentation as `page:{slug}` — so `operações` and `operacoes` being
 * two strings that look like one is a bug waiting for a reader.
 */
function slugPasses(?string $slug): bool
{
    return Validator::make(['slug' => $slug], ['slug' => ['nullable', new AsciiSlug]])->passes();
}

it('accepts a plain lowercase slug', function (string $slug) {
    expect(slugPasses($slug))->toBeTrue();
})->with(['solucoes', 'sap-erp', 'zfl-bloq-desbloq-cliente', 'v2', 'a', 'a1-b2-c3']);

it('refuses accents, cedillas and anything else that is not [a-z0-9-]', function (string $slug) {
    expect(slugPasses($slug))->toBeFalse();
})->with([
    'soluções',        // ç and ~
    'operação',
    'café',
    'Solucoes',        // uppercase
    'sap erp',         // space
    'sap_erp',         // underscore
    'sap--erp',        // doubled hyphen
    '-sap',            // leading
    'sap-',            // trailing
    'sap/erp',
    'sap.erp',
    'ação%20',
]);

it('leaves emptiness to the nullable rule', function () {
    expect(slugPasses(null))->toBeTrue()
        ->and(slugPasses(''))->toBeTrue();
});

it('suggests the transliterated form, which is what the generators produce', function () {
    $validator = Validator::make(['slug' => 'Soluções & Cia'], ['slug' => [new AsciiSlug]]);

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('slug'))->toContain('solucoes-cia')
        ->and(Str::slug('Soluções & Cia'))->toBe('solucoes-cia');
});

it('refuses an accented slug posted to the catalogs, which accepted anything before', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $solution = Solution::factory()->create();

    $response = $this->actingAs($admin)->patchJson(route('solutions.update', $solution), [
        'name'     => $solution->name,
        'slug'     => 'integração-sap',
        'category' => 'erp',
        'status'   => 'active',
    ]);

    expect($response->status())->toBe(422)
        ->and($response->json('message'))->toContain('sem acento')
        ->and($solution->fresh()->slug)->toBe($solution->slug);
});

it('applies the same rule to people and companies', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $person = Person::factory()->create();
    $this->actingAs($admin)->patchJson(route('people.update', $person), [
        'name' => $person->name, 'slug' => 'joão-silva',
    ])->assertStatus(422);

    $company = Company::factory()->create();
    $this->actingAs($admin)->patchJson(route('companies.update', $company), [
        'name' => $company->name, 'slug' => 'construção-ltda',
    ])->assertStatus(422);
});

it('generates slugs that satisfy the rule, for names full of accents', function (string $name) {
    expect(slugPasses(Str::slug($name)))->toBeTrue();
})->with([
    'Soluções Integradas Ltda',
    'Operação & Manutenção',
    'João da Silva Ção',
    'ÁÉÍÓÚ ÀÃÕ Çç',
]);
