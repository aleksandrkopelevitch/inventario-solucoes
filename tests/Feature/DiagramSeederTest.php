<?php

use App\Models\Diagram;
use App\Models\Solution;
use Database\Seeders\DiagramSeeder;
use Database\Seeders\SolutionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->seed(SolutionSeeder::class);
    $this->seed(DiagramSeeder::class);
});

it('creates planned solutions for participants missing from the inventory', function () {
    foreach (['gupy', 'active-directory', 'repom-edenred', 'freshdesk', 'indecx', 'viasoft-construshow', 'unica'] as $slug) {
        $solution = Solution::where('slug', $slug)->first();
        expect($solution)->not->toBeNull()
            ->and($solution->status)->toBe('planned');
    }
});

it('models the full VPR chain with ordered participants', function () {
    $vpr = Diagram::where('slug', 'toll-voucher-vpr')->firstOrFail();

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

    Diagram::all()->each(function (Diagram $diagram) use ($digibee) {
        $pivot = $diagram->participants()->where('solutions.id', $digibee->id)->first()?->pivot;
        $lastPosition = $diagram->participants()->max('position');

        expect($pivot)->not->toBeNull()
            ->and($pivot->position)->toBeGreaterThan(0)
            ->and($pivot->position)->toBeLessThan($lastPosition);
    });
});

it('exposes a 3+ system diagram in each participant via diagram_solution', function () {
    $sap = Solution::where('slug', 'sap-s-4hana')->firstOrFail();

    // The VPR chain (6 participants) shows up among SAP's diagrams.
    expect($sap->diagrams()->pluck('slug'))->toContain('toll-voucher-vpr');
});
