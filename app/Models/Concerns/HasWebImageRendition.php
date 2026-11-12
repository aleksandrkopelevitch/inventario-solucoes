<?php

namespace App\Models\Concerns;

use App\Contracts\Documentable;
use App\Models\DocumentationPage;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The lighter WEB rendition of every raster image in a model's `docs`
 * collection — a documentation page's embedded images, and the pictures
 * pasted onto a diagram's canvas (`Diagram`, `SubmissionDiagram`), which are
 * served by the same `/files/{id}` route and were the one `docs` collection
 * without it: a 10 MB paste was drawn in a 160 px block, and embedded whole
 * into every PNG export of the drawing.
 *
 * At most 1600px wide — twice the 768px reading column, so it stays sharp on
 * a high-density screen — re-encoded as WebP. The original is never touched;
 * the lightbox and a download still get it (DocumentationMediaResponse).
 *
 * Measured on the GitBook archives before shipping: the 40 heaviest images
 * went from 57.9 MB to 3.4 MB, most of them 3000px+ screenshots shown in a
 * 768px column, and a random 80 of the rest from 12.1 MB to 2.7 MB.
 *
 * - Queued: an upload answers before the work is done, and until it is,
 *   the original is what gets served. The droplet's workers run as
 *   www-data, which is also who must own the file.
 * - `nonOptimized()`: the package would otherwise hand the WebP to `cwebp`
 *   whenever that binary happens to be installed, re-encoding it at a
 *   different quality — the server's toolbox would decide the output.
 * - 16000px tall at most because GD cannot write a WebP past 16383px in
 *   either direction, and a full-page screenshot gets there.
 *
 * `documentation:web-images` fills the backlog for every model using this.
 */
trait HasWebImageRendition
{
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($media !== null && ! in_array($media->mime_type, DocumentationPage::WEB_IMAGE_TYPES, true)) {
            return;
        }

        $this->addMediaConversion(Documentable::WEB_CONVERSION)
            ->fit(Fit::Max, 1600, 16000)
            ->format('webp')
            ->quality(80)
            ->nonOptimized()
            ->queued()
            ->performOnCollections(Documentable::DOCS_COLLECTION);
    }
}
