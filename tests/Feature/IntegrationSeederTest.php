<?php

use App\Models\Integration;
use App\Models\Solution;
use Database\Seeders\IntegrationSeeder;
use Database\Seeders\SolutionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->seed(SolutionSeeder::class);
    $this->seed(IntegrationSeeder::class);
});

it('creates planned solutions for participants missing from the inventory', function () {
    foreach (['gupy', 'active-directory', 'repom-edenred', 'freshdesk', 'indecx', 'viasoft-construshow', 'unica'] as $slug) {
        $solution = Solution::where('slug', $slug)->first();
        expect($solution)->not->toBeNull()
            ->and($solution->status)->toBe('planned');
    }
});

it('models the full VPR chain with ordered participants', function () {
    $vpr = Integration::where('slug', 'toll-voucher-vpr')->firstOrFail();

    $chain = $vpr->participants()->get()
        ->map(fn ($s) => [$s->slug, $s->pivot->position]);

    // SAP -> Digibee -> Mantran -> Repom -> BigQuery -> SAP
    expect($vpr->participants()->count())->toBe(6)
        ->and($chain->pluck(1)->all())->toBe([0, 1, 2, 3, 4, 5])
        ->and($chain->pluck(0)->all())
        ->toBe(['sap-s-4hana', 'digibee-ipaas', 'mantran-tms', 'repom-edenred', 'google-bigquery', 'sap-s-4hana']);
});

it('includes Digibee as a common participant across the portfolio, never as the chain endpoint', function () {
    $digibee = Solution::where('slug', 'digibee-ipaas')->firstOrFail();

    Integration::all()->each(function (Integration $integration) use ($digibee) {
        $pivot = $integration->participants()->where('solutions.id', $digibee->id)->first()?->pivot;
        $lastPosition = $integration->participants()->max('position');

        expect($pivot)->not->toBeNull()
            ->and($pivot->position)->toBeGreaterThan(0)
            ->and($pivot->position)->toBeLessThan($lastPosition);
    });
});

it('exposes a 3+ system integration in each participant via integration_solution', function () {
    $sap = Solution::where('slug', 'sap-s-4hana')->firstOrFail();

    // A cadeia VPR (6 participantes) aparece entre as integrações do SAP.
    expect($sap->integrations()->pluck('slug'))->toContain('toll-voucher-vpr');
});
