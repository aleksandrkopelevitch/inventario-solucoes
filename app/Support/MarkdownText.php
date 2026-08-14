<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Renders the app's SHORT free-text fields — a person's notes, a company's
 * notes, a solution's description and its support × operation note — from the
 * Markdown their authors type into read-only HTML for `.ak-rich-text`
 * (resources/css/components/rich-text.css). Blade side: `x-ui.markdown`.
 *
 * Deliberately NOT `GitbookRenderer`. That one also speaks the extended
 * notation the Editor.js documentation authors ({% hint %}, {% tabs %},
 * {% file %}) and converts with `html_input=allow` — a fair trade for a
 * documentation page written in a block editor, and the wrong one for a note
 * field typed into a plain textarea. The two are separate on purpose; don't
 * merge them without deciding which of those two contracts wins.
 */
class MarkdownText
{
    public static function toHtml(?string $markdown): string
    {
        if (blank($markdown)) {
            return '';
        }

        return trim(Str::markdown($markdown, [
            // A note field is not a place to author HTML: a `<script>` (or an
            // `<img onerror=…>`) left in someone's notes would run for every
            // reader of that page. Markdown itself loses nothing by this.
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,

            // These fields were plain textareas rendered with
            // `whitespace-pre-line`, so every newline their authors typed
            // showed up as one. Markdown normally swallows a single newline,
            // which would silently reflow every note already in the database
            // into one run-on paragraph — so a soft break stays a line break.
            'renderer' => ['soft_break' => "<br />\n"],
        ]));
    }
}
