<?php

namespace App\Console\Commands;

use App\Contracts\Documentable;
use App\Models\DocumentationPage;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * `documentation:web-images` — queues the lighter rendition of every
 * documentation image that does not have one yet.
 *
 * An upload queues its own (DocumentationPage::registerMediaConversions()), so
 * what this catches is everything that never went through that: the images
 * uploaded before the rendition existed — the whole imported corpus, on the
 * first run — and any whose job failed. It runs on every deploy (Envoy.blade.php)
 * and is meant to: once the backlog is gone it finds nothing and queues nothing.
 *
 * Deliberately not `media-library:regenerate --only-missing`, which looks
 * equivalent and is not: it dispatches a job for EVERY convertible media and
 * lets each one discover there is nothing to do, so a deploy would put a
 * thousand empty jobs on the queue. This asks the database first.
 *
 * It only DISPATCHES. The conversions run on the queue workers, as www-data,
 * which is who must own the files they write — see the ownership note in
 * Envoy.blade.php for what happens when root writes into the media directories.
 */
class QueueDocumentationWebImagesCommand extends Command
{
    protected $signature = 'documentation:web-images';

    protected $description = 'Queue the lighter web rendition for documentation images that do not have one yet';

    public function handle(FileManipulator $files): int
    {
        $queued = 0;
        $missing = 0;

        Media::query()
            ->where('model_type', (new DocumentationPage)->getMorphClass())
            ->where('collection_name', Documentable::DOCS_COLLECTION)
            ->whereIn('mime_type', DocumentationPage::WEB_IMAGE_TYPES)
            // Registering the conversion asks the owning page, so the page is
            // loaded per chunk rather than once per image.
            ->with('model')
            ->lazyById()
            ->reject(fn (Media $media) => $media->hasGeneratedConversion(Documentable::WEB_CONVERSION))
            ->each(function (Media $media) use ($files, &$queued, &$missing) {
                // A row whose file is gone would fail on the worker, and fail
                // again on every deploy after it. Counted instead, so the number
                // is visible without a failed job to go looking for.
                if (! is_file($media->getPath())) {
                    $missing++;

                    return;
                }

                $files->createDerivedFiles($media, [Documentable::WEB_CONVERSION], onlyMissing: true);
                $queued++;
            });

        $this->components->info("Queued {$queued} documentation image(s) for a web rendition.");

        if ($missing > 0) {
            $this->components->warn("Skipped {$missing} whose file is missing on disk.");
        }

        return self::SUCCESS;
    }
}
