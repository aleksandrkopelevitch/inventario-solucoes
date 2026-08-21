<?php

namespace App\Support\Context;

use ZipArchive;

/**
 * Rough token accounting for anything that goes into an LLM prompt.
 *
 * This is an ESTIMATE by design and is never used to bill anything — it drives
 * the composer's context meter and the ceiling that stops a conversation from
 * quietly growing into a huge request. The tokenizer that would give an exact
 * count belongs to the provider and isn't reachable before the call, and
 * calling it per keystroke to paint a progress bar would cost more than the
 * bar saves.
 *
 * Every constant here deliberately errs on the side of OVER-counting. The
 * whole point of the meter is to stop runaway spend, and an estimate that
 * undershoots is the exact failure it exists to prevent.
 */
final class TokenEstimator
{
    /**
     * Characters per token. The familiar "~4 chars" rule is measured on
     * English prose; this app's material is PT-BR (accented characters cost
     * more) and minified JSON (punctuation-dense, splits badly), both of which
     * tokenize worse than that. 3.5 keeps the estimate on the safe side.
     */
    public const CHARS_PER_TOKEN = 3.5;

    /**
     * One image, regardless of size. Gemini bills a small image at a few
     * hundred tokens and tiles a large one, so this sits above the small case
     * without pretending to know the tiling.
     */
    private const IMAGE_TOKENS = 900;

    /**
     * A PDF is billed roughly per page, so the estimate goes through an
     * assumed page weight. 50 KB/page is a text-heavy contract or spec — the
     * kind of PDF that actually gets attached here — and a scan-heavy file
     * lands well over its real page count, which is the direction we want.
     */
    private const PDF_BYTES_PER_PAGE = 50000;

    private const PDF_TOKENS_PER_PAGE = 350;

    public static function forText(?string $text): int
    {
        return $text === null || $text === '' ? 0 : self::forChars(mb_strlen($text));
    }

    public static function forChars(int $chars): int
    {
        return $chars <= 0 ? 0 : (int) ceil($chars / self::CHARS_PER_TOKEN);
    }

    /**
     * Tokens a native (non-inlined) attachment costs.
     *
     * Takes the kind already decided by NativeAttachmentType rather than
     * re-reading a mime type here — the decision of what rides natively must be
     * made in exactly one place, or the budget and the prompt can disagree.
     */
    public static function forNativeAttachment(string $kind, int $bytes): int
    {
        return $kind === NativeAttachmentType::IMAGE ? self::IMAGE_TOKENS : self::forPdf($bytes);
    }

    public static function forPdf(int $bytes): int
    {
        return max(1, (int) ceil($bytes / self::PDF_BYTES_PER_PAGE)) * self::PDF_TOKENS_PER_PAGE;
    }

    /**
     * Upper-bound estimate for a file that has NOT been read yet — the shape a
     * budget guard needs, since it has to refuse an upload before spending the
     * work of extracting it.
     *
     * Counts one token per 3.5 BYTES of readable content rather than per
     * character. Bytes >= characters in UTF-8, so for plain text that is a true
     * ceiling. For a ZIP container it is emphatically NOT — see
     * `extractableBytes()`, which is why `$path` is worth passing.
     */
    public static function forUploadedBytes(?string $mimeType, ?string $extension, int $bytes, ?string $path = null): int
    {
        $kind = NativeAttachmentType::for($mimeType, $extension);

        return $kind === null
            ? self::forChars(self::extractableBytes($path, $bytes))
            : self::forNativeAttachment($kind, $bytes);
    }

    /**
     * Bytes of text a file can actually yield, as opposed to bytes it occupies.
     *
     * The two are the same thing for plain text and wildly different for an
     * Office file, which is a ZIP of XML: measured against this app's own
     * DocxTextExtractor, a 38 KB .docx of varied prose carries 555 KB of text
     * (14x), and a repetitive one reaches 146x. Estimating such a file by its
     * COMPRESSED size undercounts by that same factor — enough for one ordinary
     * Word document to walk straight through the context ceiling this estimate
     * exists to hold, since nothing downstream re-checks: by the time the real
     * count is known (AttachFlowspecFile, from the extracted text) the row is
     * already stored.
     *
     * The zip's central directory declares the uncompressed size of every part
     * without extracting anything — 0.025 ms for that file, and 0.016 ms to
     * fail on a 10 MB non-zip, so it is cheap enough to just try. Text is a
     * subset of the XML holding it, making that total a genuine upper bound:
     * measured at 1.27-1.34x the text finally extracted, i.e. erring high,
     * which is this class's whole policy. A declared size that lies only ever
     * lies upward here (a crafted archive gets refused, not admitted).
     *
     * Anything that won't open as a zip falls back to its own size, which is
     * the right answer twice over: plain text really is its own ceiling, and a
     * corrupt archive is a file SourceTextExtractor will fail to read, and a
     * failed read costs nothing at all.
     */
    private static function extractableBytes(?string $path, int $bytes): int
    {
        if ($path === null || ! is_readable($path)) {
            return $bytes;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return $bytes;
        }

        $uncompressed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat !== false) {
                $uncompressed += (int) $stat['size'];
            }
        }

        $zip->close();

        return max($bytes, $uncompressed);
    }

    /** Character budget equivalent to a token allowance — the inverse, for trimming text to fit. */
    public static function charsFor(int $tokens): int
    {
        return (int) floor($tokens * self::CHARS_PER_TOKEN);
    }
}
