<?php

use Illuminate\Support\Facades\Blade;

/**
 * The edges SVG carries its own stylesheet AND the arrowhead markers. Both are
 * load-bearing, and one silently destroys the other when a `<` slips into the
 * CSS: inside an inline SVG the parser reads `<style>` content as MARKUP, not
 * as raw text, so a `<` opens a tag, the real `</style>` closes it, and every
 * node written after it — the whole `<defs>` — ends up nested inside the style
 * element, where nothing renders. The markup still LOOKS right in view source.
 */
function chainVizEdgesSvg(): string
{
    $html = Blade::render('<x-chain.viz />');
    $start = strpos($html, '<svg data-viz-edges');
    expect($start)->not->toBeFalse('the edges <svg> is gone from the component');

    return substr($html, $start, strpos($html, '</svg>', $start) - $start);
}

it('keeps the edges stylesheet free of markup characters', function () {
    preg_match('/<style>(.*?)<\/style>/s', chainVizEdgesSvg(), $m);

    expect($m)->not->toBeEmpty('the edges <svg> lost its internal stylesheet');
    expect($m[1])->not->toContain('<');
});

it('defines the arrow markers outside the stylesheet', function () {
    $svg = chainVizEdgesSvg();
    $styleEnd = strpos($svg, '</style>');

    foreach (['data-viz-marker-end', 'data-viz-marker-start'] as $marker) {
        $at = strpos($svg, $marker);
        expect($at)->not->toBeFalse("{$marker} is missing from the edges <svg>");
        expect($at)->toBeGreaterThan($styleEnd, "{$marker} was parsed into the stylesheet, so it never renders");
    }
});

it('keeps the pointer targets on a layer of their own', function () {
    // `drawEdgeHit()` appends into `[data-viz-hits]` and `clearOverlays()` calls
    // `hits.replaceChildren()` with no guard: rename or drop this element and
    // the first `render()` throws, which is every diagram selection.
    $html = Blade::render('<x-chain.viz />');
    $hits = strpos($html, 'data-viz-hits');
    $edges = strpos($html, '<svg data-viz-edges');

    expect($hits)->not->toBeFalse('the hit layer is gone')
        ->and($hits)->toBeLessThan($edges, 'the hit layer must not live inside the edges SVG');
});

it('styles every path class the canvas paints, or lets it carry its own attributes', function () {
    // The generalisation of two bugs that already shipped: the export clones a
    // nested SVG WITHOUT any stylesheet, so a class styled only in the outer
    // sheet falls back to the browser's `fill: black`. That cost the arrowheads
    // once and painted a blob over every arrow once.
    $js = file_get_contents(base_path('resources/js/modules/chain-viz.js'));
    preg_match_all("/setAttribute\('class', '(ak-viz-[a-z-]+)'/", $js, $m);

    expect($m[1])->not->toBeEmpty('the scan found no painted class — the call shape changed');

    $svg = chainVizEdgesSvg();
    // `ak-viz-edge-hit` lives in the hits SVG, which has no internal sheet at
    // all; it is safe because `drawEdgeHit()` sets fill/stroke as presentation
    // attributes, which travel with any clone.
    $byAttribute = ['ak-viz-edge-hit'];

    foreach (array_unique($m[1]) as $class) {
        if (in_array($class, $byAttribute, true)) {
            expect($js)->toContain("hit.setAttribute('fill', 'none')");

            continue;
        }

        expect(str_contains($svg, $class))
            ->toBeTrue("{$class} is painted by the canvas but styled nowhere the export can see");
    }
});
