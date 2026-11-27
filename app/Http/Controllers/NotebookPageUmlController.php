<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckPageUmlRequest;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Support\Documentation\UmlDiagram;
use App\Support\Documentation\UmlSolutionBindings;
use App\Support\GitbookRenderer;
use Illuminate\Http\JsonResponse;

/**
 * Validates a UML diagram for the editor's block, with the SAME validator the
 * reader draws with — so the editor can say, while somebody types, what the
 * published page would refuse. Before this, the preview drew diagrams the page
 * then showed as an error box (an element with no `at`, an unknown `kind`,
 * two elements in one cell): the browser preview is lenient by design, and a
 * second validator written in JavaScript would drift from this one.
 *
 * It also reads the diagram's catalog BINDINGS (`"solution": "<slug>"`,
 * UmlSolutionBindings): a slug the catalog does not have is a problem — the
 * page would draw the diagram, but no relationship would come of it — and an
 * unbound element named like a solution is a HINT, never a refusal. And it
 * answers the bound solutions' logos, which the preview draws as badges.
 *
 * Writes nothing. The page is in the route only for the authorization and the
 * URL shape the scene block already uses.
 */
class NotebookPageUmlController extends Controller
{
    public function check(CheckPageUmlRequest $request, Notebook $notebook, DocumentationPage $page, UmlSolutionBindings $bindings): JsonResponse
    {
        $parsed = UmlDiagram::parse(
            (string) $request->validated('type'),
            (string) ($request->validated('title') ?? ''),
            (string) $request->validated('source'),
        );

        if ($parsed['diagram'] === null) {
            return response()->json(['problems' => $parsed['problems'], 'bindings' => [], 'hints' => [], 'logos' => (object) []]);
        }

        // The page draws a diagram with an unknown slug — it only derives no
        // relationship from it — so that is not one of `problems`, which the
        // block words as "a página vai mostrar este diagrama como erro".
        $check = $bindings->check($parsed['diagram']);

        return response()->json([
            'problems' => [],
            'bindings' => $check['problems'],
            'hints'    => $check['hints'],
            // The badges the reader draws on bound elements, so the preview
            // draws them too.
            'logos' => (object) GitbookRenderer::umlLogos(UmlSolutionBindings::slugsOf($parsed['diagram'])),
        ]);
    }
}
