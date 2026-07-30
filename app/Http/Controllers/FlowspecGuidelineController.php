<?php

namespace App\Http\Controllers;

use App\Actions\Flowspec\SaveFlowspecGuideline;
use App\Http\Requests\StoreFlowspecGuidelineRequest;
use App\Http\Requests\UpdateFlowspecGuidelineRequest;
use App\Models\FlowspecGuideline;
use App\View\Components\Flowspec\GuidelineList;
use Illuminate\Http\JsonResponse;

/**
 * "Gerenciar diretrizes" area (F8) — CRUD for the guideline documents always
 * folded into the Especialista em Integrações' system prompt. Admin-only,
 * lives only inside the shared `#main-modal` (never its own page), opened
 * from the flowSpec chat top bar next to "Referências".
 */
class FlowspecGuidelineController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', FlowspecGuideline::class);

        return response()->json([
            'content' => view('flowspec.guidelines.manage')->render(),
        ]);
    }

    public function store(StoreFlowspecGuidelineRequest $request, SaveFlowspecGuideline $save): JsonResponse
    {
        $guideline = $save->handle([
            'title'   => $request->validated('title'),
            'content' => $request->validated('content'),
        ]);

        return $this->saved("\"{$guideline->title}\" adicionada às diretrizes.");
    }

    public function update(UpdateFlowspecGuidelineRequest $request, FlowspecGuideline $guideline, SaveFlowspecGuideline $save): JsonResponse
    {
        $save->handle([
            'title'     => $request->validated('title'),
            'content'   => $request->validated('content'),
            'is_active' => $request->boolean('is_active'),
        ], $guideline);

        return $this->saved("\"{$guideline->title}\" atualizada.");
    }

    public function destroy(FlowspecGuideline $guideline): JsonResponse
    {
        $this->authorize('delete', $guideline);

        $title = $guideline->title;
        $guideline->delete();

        return $this->saved("\"{$title}\" removida das diretrizes.");
    }

    private function saved(string $message): JsonResponse
    {
        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => [GuidelineList::slot()],
        ]);
    }
}
