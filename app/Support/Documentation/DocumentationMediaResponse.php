<?php

namespace App\Support\Documentation;

use App\Contracts\Documentable;
use App\Models\DocumentationPage;
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
        self::cachePolicy($response, $media, $light !== null);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * How long a browser may keep the answer without asking.
     *
     * The file behind a media id never changes — a replaced upload is a new
     * row — so the only thing that can change an answer is the rendition
     * ARRIVING: an `<img>` served the upload while the conversion is still on
     * the queue should come back for the lighter file. So that one case keeps
     * revalidating (`no-cache`, answered 304 until the rendition lands, then
     * 200 with the WebP), and every other answer is final: the rendition
     * itself, a type that never gets one (GIF, SVG, a PDF), a rendition the
     * encoder made bigger than the upload, and anything asked for as a
     * document or with `?original=1`.
     *
     * Final answers are `private, immutable` for 30 days. `private` because
     * the three routes serving this each decide who may have the file, and a
     * shared cache must not answer for the next person. Before this there was
     * no `Cache-Control` at all, which Symfony fills as `private,
     * must-revalidate`: every image of every page came back through the whole
     * Laravel stack on every view, only to answer 304.
     */
    private static function cachePolicy(BinaryFileResponse $response, Media $media, bool $servedRendition): void
    {
        $convertible = in_array(strtolower((string) $media->mime_type), DocumentationPage::WEB_IMAGE_TYPES, true);
        $awaitingRendition = $convertible && ! $media->hasGeneratedConversion(Documentable::WEB_CONVERSION);

        if (! $servedRendition && $awaitingRendition) {
            $response->setCache(['private' => true, 'no_cache' => true]);

            return;
        }

        $response->setCache(['private' => true, 'max_age' => 2592000, 'immutable' => true]);
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

    /**
     * The types a browser may open IN PLACE, on this app's origin.
     *
     * Everything else is an attachment. An SVG is the reason: it is accepted
     * as an upload, and opened as a document (a file card's link, a new tab)
     * it is a page that runs its own `<script>` with the session of whoever
     * opened it — an editor's upload acting as the admin who clicked it. The
     * list is by the DETECTED type (Spatie reads the bytes), so an HTML file
     * renamed `.txt` lands outside it too. An `<img>` ignores
     * `Content-Disposition`, which is why the SVGs drawn in a page are not
     * affected.
     */
    private const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf', 'text/plain',
    ];

    /** @return array<string, string> */
    private static function headers(string $mimeType, string $fileName): array
    {
        $inline = in_array(strtolower($mimeType), self::INLINE_TYPES, true);

        return [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($fileName) . '"',
            // Never let the browser second-guess the type into something it runs.
            'X-Content-Type-Options' => 'nosniff',
        ] + ($inline ? [] : [
            // Belt and braces for the attachment: should anything still render
            // it as a document, it renders with no script and no origin.
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}
