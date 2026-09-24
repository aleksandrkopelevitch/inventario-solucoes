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

it('keeps the links pointer targets out of the edges SVG', function () {
    // They are 16px wide and follow every route; inside the edges SVG — a
    // LATER sibling of the lanes — they sat on top of the 10px strip a lane is
    // resized by, and killed the drag wherever a link crossed a lane boundary.
    $html = Blade::render('<x-chain.viz />');

    $hits = strpos($html, '<svg data-viz-hits');
    $edges = strpos($html, '<svg data-viz-edges');

    expect($hits)->not->toBeFalse('the pointer-target layer is gone from the component')
        ->and($hits)->toBeLessThan($edges, 'the targets layer must be its own sibling, not a child of the edges SVG');

    expect(chainVizEdgesSvg())->not->toContain('data-viz-hits');

    // The rule that makes the 16px band catch the pointer has to follow the
    // layer: left scoped to `.ak-viz-edges`, it matches nothing and a 2px line
    // becomes unclickable again.
    expect($html)->toContain('.ak-viz-hits path.ak-viz-edge-hit');
});
