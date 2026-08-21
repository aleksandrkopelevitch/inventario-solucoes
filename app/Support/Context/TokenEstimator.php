<?php

namespace App\Support\Context;

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
     * For text and Office formats it counts one token per 3.5 BYTES rather than
     * characters. Bytes >= characters in UTF-8, so for plain text that is a
     * true ceiling; for zipped XML it is a proxy that can read either way, and
     * reading high is the direction this class always chooses.
     */
    public static function forUploadedBytes(?string $mimeType, ?string $extension, int $bytes): int
    {
        $kind = NativeAttachmentType::for($mimeType, $extension);

        return $kind === null
            ? self::forChars($bytes)
            : self::forNativeAttachment($kind, $bytes);
    }

    /** Character budget equivalent to a token allowance — the inverse, for trimming text to fit. */
    public static function charsFor(int $tokens): int
    {
        return (int) floor($tokens * self::CHARS_PER_TOKEN);
    }
}
