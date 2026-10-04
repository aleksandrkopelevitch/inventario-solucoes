<?php

use App\Models\AttributeOption;
use App\Models\Solution;
use App\View\Components\Solutions\DetailHeader;
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

    // Category = its color family (iam → rose, via CategoryPalette) instead of
    // the old solid-green anchor — green is no longer the only color. The logo
    // tile takes the same family as a discreet gradient (`from-cat-rose …`).
    // High criticality = red; no gray `rounded-full bg-raised` pill. Guest (no
    // edit permission) renders a <span>; the editable <select> uses the same
    // classes with a `!` prefix.
    expect($html)->toContain('bg-cat-rose-soft')                // category chip = rose family
        ->and($html)->toContain('text-cat-rose-ink')
        ->and($html)->toContain('from-cat-rose')                // logo tile = rose family (gradient)
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

it('renders diagram blocks white, keeping only the two flow terminals coloured', function () {
    $html = Blade::render('<x-chain.viz />');

    // White is the ground for every block (2026-08-26): shape says what a
    // block IS, and colour is left to mean whatever the author decides. The
    // per-kind pastels that used to live here are gone, so the tokens naming
    // them must be gone too — a leftover `--viz-node-actor` would be a colour
    // nothing reads.
    expect($html)->toContain('--viz-node: #FFFFFF')
        ->and($html)->toContain('--viz-node-start: #22C55E')
        ->and($html)->toContain('--viz-node-end: #EF4444')
        ->and($html)->not->toContain('--viz-node-free')
        ->and($html)->not->toContain('--viz-node-actor')
        ->and($html)->not->toContain('--viz-node-decision')
        ->and($html)->toContain('--viz-select: #4A90D9') // blue selection ring
        ->and($html)->toContain('.ak-viz-handle')    // draggable endpoint handles
        ->and($html)->toContain('.ak-viz-anchor');    // candidate anchors (4 + 2 + 2)
});

it('draws the decision as the reference profile\'s solid orange diamond', function () {
    $html = Blade::render('<x-chain.viz />');

    // One clipped fill layer, a true rhombus — the four ports sit on its four
    // tips because the block's box stays rectangular. The old white hexagon
    // needed a second, outline layer; a solid fill needs none.
    expect($html)
        ->toContain('--viz-decision: #F6C453;')
        ->toMatch('/is-decision::before \{[^}]*clip-path: polygon\(50% 0, 100% 50%, 50% 100%, 0 50%\);[^}]*background: var\(--viz-decision\);/s')
        ->not->toContain('.ak-viz-node.is-decision::after')
        // The text, ports and comment badge are lifted above the fill layer.
        ->toContain('.ak-viz-node.is-decision .ak-viz-node-body,');
});

it('gives the actor the same round silhouette as the flow terminals', function () {
    $html = Blade::render('<x-chain.viz />');

    // One CSS rule for all three, so an actor can never drift back into being
    // a box with the icon beside its text.
    expect($html)->toContain(".ak-viz-node.is-actor {\n                background: var(--viz-node);")
        ->and($html)->toMatch('/\.ak-viz-node\.is-start,\s*\n\s*\.ak-viz-node\.is-end,\s*\n\s*\.ak-viz-node\.is-actor \{/')
        // The label under a round block wears a chip with the block's own
        // hairline, since it sits on open canvas.
        ->and($html)->toContain('box-shadow: 0 0 0 1px var(--viz-node-border);');
});

it('draws the four tones and the pill from the stylesheet, never inline', function () {
    $html = Blade::render('<x-chain.viz />');

    foreach (['blue' => '#2F6FDB', 'red' => '#D03B35', 'green' => '#2E9C5C', 'orange' => '#D9531E'] as $tone => $border) {
        expect($html)->toMatch('/\.ak-viz-node\[data-tone="' . $tone . '"\] \{[^}]*border-width: 2px; border-color: ' . $border . ';/');
    }

    expect($html)->toMatch('/\.ak-viz-node\.is-pill \{[^}]*border-radius: 999px;[^}]*background: var\(--viz-pill\);/s')
        ->and($html)->toContain('--viz-pill: #17212B;');
});

it('gives the logo fallback a solid green anchor block (no gray tile)', function () {
    $html = Blade::render('<x-ui.logo name="AccessOne" />');

    expect($html)->toContain('bg-accent')
        ->and($html)->toContain('text-white')
        ->and($html)->not->toContain('bg-raised');
});
