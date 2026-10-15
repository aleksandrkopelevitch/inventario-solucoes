<?php

namespace App\Support\Documentation;

use App\Contracts\Documentable;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The bytes behind a documentation file URL — `/files/{id}`,
 * `/docs/{caderno}/file/{id}` and `/public-docs/{token}/file/{id}` alike. Each
 * route decides WHO may have the file; this decides WHICH file they get.
 *
 * An image has two: the upload itself, and its lighter WEB_CONVERSION (see
 * DocumentationPage::registerMediaConversions()). The light one goes to an
 * `<img>` — the page body, a card, the editor — and the upload to everything
 * else:
 *
 * - **The choice is read from `Sec-Fetch-Dest`**, which the browser sets and a
 *   page cannot: `image` is a request an `<img>` made. Following a link to the
 *   file, opening it in a new tab or downloading it is a `document`, and gets
 *   the upload — so a PNG attached as a file is never handed over re-encoded.
 *   No header at all (an old browser, a script) gets the upload too: heavier,
 *   never wrong.
 * - **`?original=1` asks for the upload explicitly.** That is how the lightbox
 *   loads the full image, since its preload is an image request like any other.
 * - **The rendition is served only once it exists** (it is generated on the
 *   queue) **and only while it is actually lighter** — a small palette PNG can
 *   come out of the WebP encoder bigger than it went in.
 *
 * The URL never changes, and that is the point: the Markdown keeps saying
 * `/files/{id}`, so the images already in the corpus got lighter without a page
 * being rewritten. `Vary: Sec-Fetch-Dest` tells a cache the same URL answers
 * two ways.
 *
 * A browser that already holds the file asks whether it changed
 * (`If-Modified-Since`, against the `Last-Modified` every file response
 * carries) and gets a bodiless 304 when it has not. Nothing answered that
 * before, so every reload downloaded every image of the page again. When a
 * rendition appears after the upload was cached, it is newer than the upload,
 * so the question comes back "modified" and the lighter file replaces it.
 */
final class DocumentationMediaResponse
{
    public static function for(Media $media, Request $request): BinaryFileResponse
    {
        $light = $request->boolean('original') || $request->header('Sec-Fetch-Dest') !== 'image'
            ? null
            : self::lighterPath($media);

        $response = $light === null
            ? response()->file($media->getPath(), self::headers($media->mime_type, $media->file_name))
            : response()->file($light, self::headers('image/webp', pathinfo($media->file_name, PATHINFO_FILENAME) . '.webp'));

        $response->setVary('Sec-Fetch-Dest', false);
        $response->isNotModified($request);

        return $response;
    }

    /** The rendition's path, when there is one worth serving instead of the upload. */
    private static function lighterPath(Media $media): ?string
    {
        if (! $media->hasGeneratedConversion(Documentable::WEB_CONVERSION)) {
            return null;
        }

        $path = $media->getPath(Documentable::WEB_CONVERSION);

        return is_file($path) && filesize($path) < $media->size ? $path : null;
    }

    /** @return array<string, string> */
    private static function headers(string $mimeType, string $fileName): array
    {
        return [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'inline; filename="' . addslashes($fileName) . '"',
        ];
    }
}
