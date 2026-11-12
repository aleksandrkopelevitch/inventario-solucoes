<?php

namespace App\Support\Documentation;

use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The bytes of a diagram's rendered picture — the catalog's
 * (`diagrams.picture.show`), a submission's and a citation's on either reading
 * surface. Each route decides WHO may have it; this decides how it is SENT,
 * which is the half that was costing the most.
 *
 * Three things every picture response carries now, none of which the previous
 * two implementations did (Spatie's `toResponse()`, which sent
 * `must-revalidate` with no validator, and a bare `readfile()` stream with no
 * headers at all — so every page citing a drawing re-downloaded the whole PNG
 * on every view):
 *
 * - **`Last-Modified` and an `ETag`**, so a browser that holds the file asks
 *   and gets a bodiless 304.
 * - **`Cache-Control: private, immutable` for a YEAR when the URL is
 *   versioned** (`?v={media id}`). A diagram's picture is a `singleFile()`
 *   collection, so every save makes a new media row with a new id — the URL
 *   with `v` in it names one exact file for ever, and the next save renders a
 *   new URL. Every `<img>` the app emits carries it (`versionedUrl()`).
 * - **`no-cache` without it**: the bare route stays correct for a link somebody
 *   typed or a page rendered before the version landed, at the price of one
 *   revalidation per view.
 *
 * `private`, because every one of these routes is behind a decision about the
 * viewer — a shared cache must never answer for the next person.
 */
final class PictureResponse
{
    /** The query parameter that pins a URL to one media row. */
    public const VERSION_PARAM = 'v';

    public static function for(Media $media, Request $request): BinaryFileResponse
    {
        $response = response()->file($media->getPath(), [
            'Content-Type'           => $media->mime_type,
            'Content-Disposition'    => 'inline; filename="' . addslashes($media->file_name) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setEtag((string) $media->id . '-' . $media->size);

        if ($request->query(self::VERSION_PARAM) !== null) {
            $response->setCache(['private' => true, 'max_age' => 31536000, 'immutable' => true]);
        } else {
            $response->setCache(['private' => true, 'no_cache' => true]);
        }

        $response->isNotModified($request);

        return $response;
    }

    /**
     * A route's URL with the picture's version pinned, so the response above
     * can be cached for good. `$parameters` are the route's own.
     *
     * @param  array<int|string, mixed>  $parameters
     */
    public static function versionedUrl(string $route, array $parameters, Media $picture): string
    {
        return route($route, $parameters + [self::VERSION_PARAM => $picture->id]);
    }
}
