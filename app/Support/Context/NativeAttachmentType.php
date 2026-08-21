<?php

namespace App\Support\Context;

/**
 * Decides whether a file goes to the model as a native attachment, and as
 * which kind.
 *
 * This exists as one shared decision because it is asked in two places that
 * must never disagree: at ingest time, to price the file into the context
 * budget, and at prompt time, to actually hand it to the API. A file the budget
 * charged for but the prompt dropped (or the reverse) is a meter that lies.
 *
 * The EXTENSION is consulted alongside the mime type, the same way
 * App\Services\Documentation\ContextDocumentResolver does. A stored mime is
 * whatever the server sniffed from the bytes, and that is regularly less
 * specific than the file plainly is — `application/octet-stream` for a PDF
 * behind a strict upload proxy, `application/x-empty` for a zero-byte one.
 * Trusting only the mime silently turns those into context that costs nothing
 * and contributes nothing.
 */
final class NativeAttachmentType
{
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public const IMAGE = 'image';

    public const PDF = 'pdf';

    /** @return self::IMAGE|self::PDF|null */
    public static function for(?string $mimeType, ?string $extension): ?string
    {
        $mimeType = (string) $mimeType;
        $extension = mb_strtolower(ltrim((string) $extension, '.'));

        if (str_starts_with($mimeType, 'image/') || in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return self::IMAGE;
        }

        return $mimeType === 'application/pdf' || $extension === 'pdf' ? self::PDF : null;
    }
}
