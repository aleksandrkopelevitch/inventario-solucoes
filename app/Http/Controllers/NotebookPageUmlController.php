<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckPageUmlRequest;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Support\Documentation\UmlDiagram;
use Illuminate\Http\JsonResponse;

/**
 * Validates a UML diagram for the editor's block, with the SAME validator the
 * reader draws with — so the editor can say, while somebody types, what the
 * published page would refuse. Before this, the preview drew diagrams the page
 * then showed as an error box (an element with no `at`, an unknown `kind`,
 * two elements in one cell): the browser preview is lenient by design, and a
 * second validator written in JavaScript would drift from this one.
 *
 * Writes nothing. The page is in the route only for the authorization and the
 * URL shape the scene block already uses.
 */
class NotebookPageUmlController extends Controller
{
    public function check(CheckPageUmlRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $parsed = UmlDiagram::parse(
            (string) $request->validated('type'),
            (string) ($request->validated('title') ?? ''),
            (string) $request->validated('source'),
        );

        return response()->json(['problems' => $parsed['problems']]);
    }
}
