<?php

namespace App\Http\Controllers;

use App\Contracts\Documentable;
use App\Models\Notebook;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves media embedded in documentation (the `docs` collection) through the
 * stable `/files/{id}` URL used inside Markdown (<img src="/files/{id}">,
 * {% file src="/files/{id}" %}). Lives under the `auth` group, so only
 * authenticated users can access it — the docs are private like the rest of
 * the inventory.
 */
class MediaController extends Controller
{
    public function show(Media $media): BinaryFileResponse
    {
        abort_unless($media->collection_name === Documentable::DOCS_COLLECTION, 404);

        // Documentation media belongs to the documentation module — closed to
        // an account whose level there is None. (`/docs` and the magic link
        // serve published media through routes of their own.)
        $this->authorize('viewAny', Notebook::class);

        return response()->file($media->getPath(), [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }
}
