<?php

use App\Models\AttributeOption;
use App\Models\Integration;
use App\Models\Solution;
use App\View\Components\Solutions\DetailHeader;
use App\View\Components\Solutions\IntegrationsMap;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

it('renders the solution header badges with semantic tones instead of a single gray', function () {
    $solution = Solution::factory()->create(['category' => 'iam', 'criticality' => 'high']);
    AttributeOption::create(['group' => 'category', 'value' => 'iam', 'label' => 'IAM']);
    AttributeOption::create(['group' => 'criticality', 'value' => 'high', 'label' => 'Alta']);
    Cache::flush(); // AttributeOption::options() is cached per group

    // Isolated component (via tag, so the lifecycle injects the public
    // $solution prop) — this way the HTML is just the header, no layout chrome.
    $html = Blade::render('<x-solutions.detail-header :solution="$solution" />', ['solution' => $solution]);

    // Category = solid green block (anchor); high criticality = red;
    // no gray `rounded-full bg-raised` pill. Guest (no edit permission)
    // renders the usual <span> — the tones stay the same; the editable
    // version (<select>) uses the same classes with a `!` prefix (see
    // Solutions\DetailHeader).
    expect($html)->toContain('bg-accent text-white')           // category anchor
        ->and($html)->toContain('ring-crit-line')               // high criticality = red
        ->and($html)->not->toContain('rounded-full bg-raised'); // no gray pill
});

it('maps criticality to a semantic tone from the raw value', function () {
    $method = new ReflectionMethod(DetailHeader::class, 'criticalityTone');

    $tone = fn (?string $value) => $method->invoke(
        new DetailHeader(Solution::factory()->make(['criticality' => $value]))
    );

    expect($tone('high'))->toBe('crit')
        ->and($tone('critical'))->toBe('crit')
        ->and($tone('medium'))->toBe('amber')
        ->and($tone('low'))->toBe('green')
        ->and($tone(null))->toBe('green');
});

it('highlights the selected integration row with a lime border on a white background', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create();
    $integration->participants()->attach($solution->id, ['position' => 0]);

    $html = (new IntegrationsMap($solution))->render()->render();

    // Selected = lime border/ring on white background (no green fill).
    expect($html)->toContain('aria-pressed:border-lime')
        ->and($html)->toContain('aria-pressed:bg-surface')
        ->and($html)->not->toContain('aria-pressed:bg-accent-soft');
});

it('renders diagram nodes in the navy/blue palette with shadow and draggable-anchor styling', function () {
    $html = Blade::render('<x-solutions.integration-viz />');

    expect($html)->toContain('--viz-node: #C9D4F7')  // lavender/bluish nodes (reference mind-map palette)
        ->and($html)->toContain('--viz-select: #4A90D9') // blue selection ring
        ->and($html)->toContain('.ak-viz-handle')    // draggable endpoint handles
        ->and($html)->toContain('.ak-viz-anchor');    // candidate anchors (4 + 2 + 2)
});

it('gives the logo fallback a solid green anchor block (no gray tile)', function () {
    $html = Blade::render('<x-ui.logo name="AccessOne" />');

    expect($html)->toContain('bg-accent')
        ->and($html)->toContain('text-white')
        ->and($html)->not->toContain('bg-raised');
});
