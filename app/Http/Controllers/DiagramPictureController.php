<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDiagramPictureRequest;
use App\Models\Diagram;
use App\Support\Documentation\PictureResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The rendered picture of a diagram's canvas, posted by the client after every
 * layout save.
 *
 * Kept out of `DiagramController` on purpose: that controller owns the chain
 * invariant ("`saveLayout()` writes only `viz_layout`, never the chain or the
 * derived columns"), and folding a media write into it would blur exactly the
 * line its docblock draws. This writes only media, and nothing here ever
 * influences topology.
 */
class DiagramPictureController extends Controller
{
    public function store(StoreDiagramPictureRequest $request, Diagram $diagram): JsonResponse
    {
        $diagram
            ->addMedia($request->file('image'))
            ->usingFileName("{$diagram->slug}-diagrama.png")
            ->toMediaCollection(Diagram::DIAGRAM_COLLECTION);

        // No updatableSlots: the canvas already shows the drawing — this is a
        // derived copy for other consumers (the CATI deck), and re-rendering
        // anything on screen would be noise.
        return response()->json(['type' => 'success']);
    }

    public function show(Request $request, Diagram $diagram): BinaryFileResponse
    {
        $this->authorize('view', $diagram);

        $media = $diagram->picture();

        abort_if($media === null, 404);

        return PictureResponse::for($media, $request);
    }
}
