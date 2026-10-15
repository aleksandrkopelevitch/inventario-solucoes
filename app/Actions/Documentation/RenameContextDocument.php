<?php

namespace App\Actions\Documentation;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Renames one of a caderno's context documents.
 *
 * Only the BASE name is the person's to change: the extension is what
 * ContextDocumentResolver reads to decide whether a document is inlined as text
 * or handed over as a PDF/image, so a rename that could turn `spec.json` into
 * `spec.pdf` would silently change how the assistant receives it. Typing the
 * same extension back is tolerated rather than doubled.
 *
 * Changing `file_name` is enough to move the file on disk: Spatie's
 * MediaObserver syncs it on `updating`.
 */
class RenameContextDocument
{
    public function handle(Media $media, string $name): Media
    {
        $media->update([
            'name'      => self::baseName($media, $name),
            'file_name' => self::fileNameFor($media, $name),
        ]);

        return $media;
    }

    /**
     * The file name a rename would produce — public so the request can check
     * it for a clash before anything is written.
     *
     * Sanitized the way Spatie sanitizes an upload (`FileAdder::defaultSanitizer`),
     * so a renamed document and a freshly uploaded one read the same in the list.
     */
    public static function fileNameFor(Media $media, string $name): string
    {
        $base = str_replace(['#', '/', '\\', ' '], '-', self::baseName($media, $name));
        $extension = strtolower((string) $media->extension);

        return $extension === '' ? $base : "{$base}.{$extension}";
    }

    private static function baseName(Media $media, string $name): string
    {
        $name = trim((string) preg_replace('#\p{C}+#u', '', $name));
        $extension = (string) $media->extension;

        if ($extension !== '' && str_ends_with(strtolower($name), '.' . strtolower($extension))) {
            $name = rtrim(substr($name, 0, -strlen($extension) - 1));
        }

        return $name;
    }
}
