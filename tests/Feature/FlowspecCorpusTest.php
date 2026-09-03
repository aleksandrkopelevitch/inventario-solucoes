<?php

use App\Models\FlowspecExample;
use App\Services\Flowspec\CredentialScrubber;
use App\Services\Flowspec\FlowspecDocument;
use Database\Seeders\FlowspecExampleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('composes the search, withAnyTag and active scopes', function () {
    FlowspecExample::factory()->tagged(['rest'])->create(['name' => 'Cache de token', 'description' => 'token jwt']);
    FlowspecExample::factory()->tagged(['ldap'])->create(['name' => 'Desbloqueio AD', 'description' => 'ldap']);
    FlowspecExample::factory()->tagged(['rest'])->inactive()->create(['name' => 'Token inativo', 'description' => 'token']);

    $results = FlowspecExample::query()->search('token')->withAnyTag(['rest'])->active()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Cache de token');
});

it('seeds the corpus from the manifest with derived connectors', function () {
    $this->seed(FlowspecExampleSeeder::class);

    expect(FlowspecExample::count())->toBeGreaterThanOrEqual(6);

    $example = FlowspecExample::query()->where('slug', 'get-token-svl')->firstOrFail();

    expect($example->connectors)->toContain('rest-connector-v2', 'object-store-connector')
        ->and($example->connectors)->toBe(FlowspecDocument::from($example->flow_spec)->connectorNames())
        ->and($example->tags)->toContain('token-cache');
});

it('seeds no example containing a literal credential', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $scrubber = new CredentialScrubber;

    FlowspecExample::query()->cursor()->each(function (FlowspecExample $example) use ($scrubber) {
        expect($scrubber->violations($example->flow_spec))->toBe([]);
    });
});

/*
|--------------------------------------------------------------------------
| Retiring examples that leave the manifest
|--------------------------------------------------------------------------
|
| `updateOrCreate` only ever adds and updates, so before `seeded_at` existed a
| deleted manifest entry survived in every environment it had been seeded into
| — active, and eligible for selection into a prompt. Ownership is tracked by
| `seeded_at` rather than by `source`, because `source` cannot answer the
| question: manifest entries carry `source: 'chat'` and the app's own
| SaveFlowspecExample writes `source: 'manual'`.
|
*/

it('deactivates a seeded example that left the manifest', function () {
    $orphan = FlowspecExample::factory()->create(['slug' => 'saiu-do-manifesto']);
    $orphan->forceFill(['seeded_at' => now()])->save();

    $this->seed(FlowspecExampleSeeder::class);

    expect($orphan->fresh()->is_active)->toBeFalse();
});

it('leaves an example created in the app alone, manifest or not', function () {
    // No `seeded_at` — this is what SaveFlowspecExample produces, and its slug
    // is deliberately absent from the manifest.
    $curated = FlowspecExample::factory()->create([
        'slug'   => 'promovido-de-uma-conversa',
        'source' => 'manual',
    ]);

    $this->seed(FlowspecExampleSeeder::class);

    expect($curated->fresh()->is_active)->toBeTrue()
        ->and($curated->fresh()->seeded_at)->toBeNull();
});

it('revives an example whose slug returns to the manifest', function () {
    $returning = FlowspecExample::factory()->inactive()->create(['slug' => 'digibee-storage-upload']);
    $returning->forceFill(['seeded_at' => now()])->save();

    $this->seed(FlowspecExampleSeeder::class);

    expect($returning->fresh()->is_active)->toBeTrue();
});

it('stamps every manifest example as seeder-owned', function () {
    $this->seed(FlowspecExampleSeeder::class);

    expect(FlowspecExample::whereNull('seeded_at')->count())->toBe(0);
});

it('is idempotent — reseeding does not duplicate examples', function () {
    $this->seed(FlowspecExampleSeeder::class);
    $count = FlowspecExample::count();

    $this->seed(FlowspecExampleSeeder::class);

    expect(FlowspecExample::count())->toBe($count);
});

it('does not read a date or an endpoint as the credential it describes', function () {
    $scrubber = new CredentialScrubber;

    // Every one of these was reported as a literal secret on the real export
    // (2026-09-02), and the same scrubber runs inside DigibeeFlowspecValidator
    // — so a GENERATED pipeline carrying an `authorizationDate` mapping was
    // failing every correction attempt and having its document discarded.
    $descriptive = ['flowSpec' => ['root' => [['params' => [
        'body' => json_encode([
            'lastPasswordUpdate' => '2022-11-14T10:22:31Z',
            'authorizationDate'  => '2026-01-02',
            'tokenUrl'           => 'https://auth.example.com/oauth/token',
            'apiKeyName'         => 'x-api-key',
        ]),
    ]]]]];

    expect($scrubber->violations($descriptive))->toBe([]);
});

it('does not read a transform mapping as a value', function () {
    $scrubber = new CredentialScrubber;

    // A JOLT spec's VALUES are the names of the fields being mapped. The whole
    // vtex-sap-pedidos-dispatcher pipeline was excluded from the teaching
    // corpus over these three.
    $mapping = ['flowSpec' => ['root' => [['transformSpec' => [[
        'spec' => [
            'payment' => [
                'authorizationToken' => 'payment.authorizationToken',
                'authorizationDate'  => '=split(\'T\',@(1,orderDate))',
            ],
        ],
    ]]]]]];

    expect($scrubber->violations($mapping))->toBe([]);
});

it('still catches a secret whose key merely sounds descriptive', function () {
    $scrubber = new CredentialScrubber;

    // The suffix guard excuses a DESCRIPTION of a credential, never a
    // credential: a bare 32-character key has no path punctuation to hide
    // behind, and `password` does not end in one of the suffixes.
    $leaky = ['flowSpec' => ['root' => [['params' => [
        'body' => json_encode(['apikey' => 'naP95LJaQ2mXv7Rt0KcE1sBd8UyHfZgW', 'password' => 'hunter2hunter2']),
    ]]]]];

    expect($scrubber->violations($leaky))->toHaveCount(3);
});

it('flags literal secrets and accepts double-braces references', function () {
    $scrubber = new CredentialScrubber;

    $clean = ['flowSpec' => ['root' => [['params' => [
        'headers' => '{"Authorization":"{{ message.token_type }} {{ message.token }}"}',
        'body'    => '{"client_secret": {{ account.custom-1.password }}}',
    ]]]]];

    expect($scrubber->violations($clean))->toBe([]);

    $leaky = ['flowSpec' => ['root' => [['params' => [
        'headers' => '{"x-api-key":"a1b2c3d4e5f6"}',
        'token'   => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0',
    ]]]]];

    expect($scrubber->violations($leaky))->not->toBe([]);
});
