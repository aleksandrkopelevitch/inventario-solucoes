<?php

namespace App\Http\Controllers;

use App\Contracts\Documentable;
use App\Models\Notebook;
use App\Models\SubmissionDiagram;
use App\Support\Documentation\DocumentationMediaResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves media embedded in documentation (the `docs` collection) through the
 * stable `/files/{id}` URL used inside Markdown (<img src="/files/{id}">,
 * {% file src="/files/{id}" %}). Lives under the `auth` group, so only
 * authenticated users can access it — the docs are private like the rest of
 * the inventory. An image comes back as its lighter rendition when an `<img>`
 * asks — see DocumentationMediaResponse.
 */
class MediaController extends Controller
{
    public function show(Request $request, Media $media): BinaryFileResponse
    {
        abort_unless($media->collection_name === Documentable::DOCS_COLLECTION, 404);

        // Asked of the module the OWNER belongs to. A committee drawing keeps
        // its pasted images in a collection of this same name (so the canvas
        // can address them as `/files/{id}`), and gating every file on the
        // documentation module let any account with no Committee access read
        // a proposal's images by counting ids — while a Committee member with
        // no Documentation level got a 403 on their own canvas. Everything
        // else here is documentation media, closed to a level of None there.
        // (`/docs` and the magic link serve published media through routes of
        // their own.)
        $owner = $media->model;

        if ($owner instanceof SubmissionDiagram) {
            $this->authorize('view', $owner->submission()->firstOrFail());
        } else {
            $this->authorize('viewAny', Notebook::class);
        }

        return DocumentationMediaResponse::for($media, $request);
    }
}
