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

it('models the full VPR chain as six nodes, and five participants, because SAP is both ends', function () {
    $vpr = Diagram::where('slug', 'toll-voucher-vpr')->firstOrFail();

    // SAP -> Digibee -> Mantran -> Repom -> BigQuery -> SAP. The chain holds
    // six NODES; the pivot holds one row per SOLUTION, so SAP appears once,
    // at the first position it occupies. That is `SyncDiagramFromChain`
    // deriving the pivot rather than the seeder asserting it — the seeder
    // used to write both, and wrote SAP twice.
    expect($vpr->chain['nodes'])->toHaveCount(6)
        ->and($vpr->chain['edges'])->toHaveCount(5);

    $participants = $vpr->participants()->get()->map(fn ($s) => [$s->slug, $s->pivot->position]);

    expect($participants->pluck(0)->all())
        ->toBe(['sap-s-4hana', 'digibee-ipaas', 'mantran-tms', 'repom-edenred', 'google-bigquery'])
        ->and($participants->pluck(1)->all())->toBe([0, 1, 2, 3, 4]);
});

it('derives every structural column from the chain it seeded, never by hand', function () {
    $cws = Diagram::where('slug', 'cws-sap-s4hana')->firstOrFail();

    // The portfolio states one arrow and one protocol per flow; direction,
    // endpoints and the summary protocol are read back out of the drawing.
    expect($cws->direction->value)->toBe('bidirectional')
        ->and($cws->protocol)->toBe('rest')
        ->and($cws->source->slug)->toBe('cws-digital-marketing')
        ->and($cws->target->slug)->toBe('sap-s-4hana');
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

    // The VPR chain shows up among SAP's diagrams.
    expect($sap->diagrams()->pluck('slug'))->toContain('toll-voucher-vpr');
});
