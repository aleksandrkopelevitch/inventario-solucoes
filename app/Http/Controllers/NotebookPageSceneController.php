<?php

namespace App\Http\Controllers;

use App\Http\Requests\DraftPageSceneRequest;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\Documentation\SceneDraftService;
use Illuminate\Http\JsonResponse;

/**
 * Proposes an animated SCENE for a page — a "fluxo em etapas" or an "antes →
 * depois", whichever `type` the editor's block asks for.
 *
 * It answers with the scene and writes nothing: the editor drops it into the
 * block the author is working on and saves the page the way it saves any other
 * edit. That is the difference from "Desenhar esta página", which creates a row
 * in another module; a scene is part of the page's own text.
 */
class NotebookPageSceneController extends Controller
{
    public function __construct(private readonly SceneDraftService $scenes) {}

    public function store(DraftPageSceneRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $content = $request->validated('content') ?? (string) $page->documentation;

        return response()->json([
            'scene' => $this->scenes->draft($request->sceneType(), $page->title, $content, $request->validated('focus')),
        ]);
    }
}
