<?php

namespace App\Http\Controllers;

use App\Actions\Flowspec\SaveFlowspecExample;
use App\Http\Requests\StoreFlowspecExampleRequest;
use App\Http\Requests\UpdateFlowspecExampleRequest;
use App\Models\FlowspecExample;
use App\View\Components\Flowspec\ExampleList;
use Illuminate\Http\JsonResponse;

/**
 * "Gerenciar referências" area (F8) — CRUD for the flowSpec example corpus
 * that feeds the Especialista em Integrações' prompt. Admin-only, lives only
 * inside the shared `#main-modal` (never its own page), opened from the
 * flowSpec chat top bar.
 * Follows the AttributeOptionController pattern: `index` returns rendered
 * modal HTML, mutations return the refreshed list slot.
 */
class FlowspecExampleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', FlowspecExample::class);

        return response()->json([
            'content' => view('flowspec.examples.manage')->render(),
        ]);
    }

    public function store(StoreFlowspecExampleRequest $request, SaveFlowspecExample $save): JsonResponse
    {
        $example = $save->handle([
            'name'        => $request->validated('name'),
            'description' => $request->validated('description'),
            'tags'        => $request->validated('tags'),
            'flow_spec'   => $request->flowSpec(),
        ]);

        return $this->saved("\"{$example->name}\" adicionado ao corpus.");
    }

    public function update(UpdateFlowspecExampleRequest $request, FlowspecExample $example, SaveFlowspecExample $save): JsonResponse
    {
        $save->handle([
            'name'        => $request->validated('name'),
            'description' => $request->validated('description'),
            'tags'        => $request->validated('tags'),
            'flow_spec'   => $request->flowSpec(),
            'is_active'   => $request->boolean('is_active'),
        ], $example);

        return $this->saved("\"{$example->name}\" atualizado.");
    }

    public function destroy(FlowspecExample $example): JsonResponse
    {
        $this->authorize('delete', $example);

        $name = $example->name;
        $example->delete();

        return $this->saved("\"{$name}\" removido do corpus.");
    }

    private function saved(string $message): JsonResponse
    {
        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => [ExampleList::slot()],
        ]);
    }
}
