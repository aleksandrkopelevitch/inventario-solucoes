<?php

use App\Models\FlowspecGuideline;
use App\Services\Flowspec\CredentialScrubber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('composes the active scope', function () {
    FlowspecGuideline::factory()->create(['title' => 'Ativa']);
    FlowspecGuideline::factory()->inactive()->create(['title' => 'Inativa']);

    $results = FlowspecGuideline::query()->active()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('Ativa');
});

it('flags a literal credential pasted into a guideline note', function () {
    $scrubber = new CredentialScrubber;

    $clean = ['content' => 'Prefira sempre referenciar segredos via {{ account.custom-1.token }}.'];
    expect($scrubber->violations($clean))->toBe([]);

    $leaky = ['content' => 'Exemplo de header: {"x-api-key":"a1b2c3d4e5f6"}'];
    expect($scrubber->violations($leaky))->not->toBe([]);
});
