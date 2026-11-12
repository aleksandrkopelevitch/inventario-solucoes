<?php

namespace App\Support\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

/**
 * The plain uploads on the `public` disk — a solution's or company's logo, a
 * person's photo, a hosting group's picture — and the THUMBNAIL each one gets.
 *
 * These are not MediaLibrary (see `.claude/rules/media-and-uploads.md`): a
 * path in a column, a file nginx serves straight from `public/storage`. What
 * they lacked was a size: a logo is uploaded at whatever the vendor's site
 * had — 1356×665 and 178 KB was a real one — and drawn at 32 px in a list of
 * ninety rows, on the ecosystem map and in every diagram block naming the
 * system. So every upload now also gets a copy at most `THUMB_SIZE` px on its
 * long side, WebP at quality 85, beside it in a `thumbs/` directory, and
 * everything that draws the image SMALL (`x-ui.logo`, `x-ui.avatar`, the map,
 * the canvas, the hosting list) asks `url()` for it. 256 px is four times the
 * largest tile (56 px) and enough for the map's zoom; the original stays for
 * the upload tile in the forms, and on disk, untouched.
 *
 * `url()` falls back to the original while the thumbnail does not exist yet —
 * `images:thumbnails` (every deploy, idempotent) fills the backlog — so a
 * missing thumbnail costs bytes, never a broken image.
 */
final class PublicImage
{
    public const DISK = 'public';

    public const THUMB_DIR = 'thumbs';

    public const THUMB_SIZE = 256;

    /** Stores the upload where it always went, and makes its thumbnail. */
    public static function store(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, self::DISK);

        self::makeThumbnail($path);

        return $path;
    }

    /**
     * The thumbnail's URL when it exists, otherwise the original's. Null for
     * nothing; an absolute URL is handed back untouched (the components take
     * either a path or a URL).
     */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        $thumb = self::thumbnailPath($path);

        return Storage::disk(self::DISK)->url(Storage::disk(self::DISK)->exists($thumb) ? $thumb : $path);
    }

    /** `solution-logos/abc.png` → `solution-logos/thumbs/abc.webp`. */
    public static function thumbnailPath(string $path): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return ($directory === '.' ? '' : $directory . '/') . self::THUMB_DIR . '/' . $name . '.webp';
    }

    /**
     * Writes the thumbnail for a stored path. False when the original is not
     * on disk or cannot be decoded (a corrupt upload is reported and left
     * serving its original, never a 500 on the form that sent it).
     */
    public static function makeThumbnail(string $path): bool
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            return false;
        }

        $thumb = self::thumbnailPath($path);
        $disk->makeDirectory(pathinfo($thumb, PATHINFO_DIRNAME));

        return rescue(function () use ($disk, $path, $thumb): bool {
            Image::load($disk->path($path))
                ->fit(Fit::Max, self::THUMB_SIZE, self::THUMB_SIZE)
                ->format('webp')
                ->quality(85)
                ->save($disk->path($thumb));

            return true;
        }, false);
    }

    /** Whether the thumbnail for a stored path is already on disk. */
    public static function hasThumbnail(string $path): bool
    {
        return Storage::disk(self::DISK)->exists(self::thumbnailPath($path));
    }
}
