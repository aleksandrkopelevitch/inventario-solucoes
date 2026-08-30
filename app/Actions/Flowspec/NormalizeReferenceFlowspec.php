<?php

namespace App\Actions\Flowspec;

/**
 * Minifies a pasted reference flowSpec and drops its top-level `meta` block
 * (the canvas x/y positions — irrelevant to the model's understanding of the
 * pipeline logic) before it is embedded in the generation prompt. On a real
 * pipeline this roughly halves the token cost of the pretty-printed paste.
 *
 * Assumes the input already parsed as JSON (App\Rules\ValidJson runs at
 * validation time); if it somehow doesn't, returns the trimmed original
 * rather than throwing.
 */
class NormalizeReferenceFlowspec
{
    /**
     * A Digibee pipeline document, as opposed to any other pasted JSON — the
     * same shape test FlowspecGenerationService applies to the model's own
     * output: a `meta` or `flowSpec` key at the top level.
     *
     * Lives here rather than on either caller because both of them need it and
     * this is the class that already knows the document's shape. The two are in
     * different modules (the F8 composer and the documentation assistant), and a
     * second copy that drifted would mean the same paste is minified on one
     * screen and stored pretty-printed on the other.
     */
    public static function looksLike(string $text): bool
    {
        $text = trim($text);

        if ($text === '' || ! in_array($text[0], ['{', '['], true)) {
            return false;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded)
            && (array_key_exists('meta', $decoded) || array_key_exists('flowSpec', $decoded));
    }

    public function handle(string $raw): string
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return trim($raw);
        }

        // Only the top-level `meta` (the canvas layout map). A nested `meta`
        // inside a step's params, if one ever exists, is left untouched.
        unset($decoded['meta']);

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
