<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIntegrationDiagramRequest;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The rendered picture of an integration's canvas.
 *
 * Kept out of SolutionIntegrationController on purpose: that controller owns
 * the chain invariant ("`saveLayout()` writes only `viz_layout`, never the
 * chain or the derived columns"), and folding a media write into it would blur
 * exactly the line its docblock draws. This writes only media, and nothing
 * here ever influences topology.
 */
class IntegrationDiagramController extends Controller
{
    public function store(StoreIntegrationDiagramRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $integration
            ->addMedia($request->file('image'))
            ->usingFileName("{$integration->slug}-diagrama.png")
            ->toMediaCollection(Integration::DIAGRAM_COLLECTION);

        // No updatableSlots: the canvas already shows the diagram — this is a
        // derived copy for other consumers (the CATI deck), and re-rendering
        // anything on screen would be noise.
        return response()->json(['type' => 'success']);
    }

    public function show(Solution $solution, Integration $integration): StreamedResponse
    {
        $this->authorize('view', $integration);

        $media = $integration->diagram();

        abort_if($media === null, 404);

        return $media->toResponse(request());
    }
}
