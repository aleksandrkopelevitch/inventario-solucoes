<?php

namespace App\Actions\Documentation;

use App\Models\DocumentationPage;

/**
 * Follows a solution's slug rename into the UML diagrams bound to it.
 *
 * The relationship rows survive a rename on their own (they hold the
 * solution's id), but the TEXT names the slug: left alone, the next save of
 * the page would resolve the old slug to nothing and drop the relationship.
 *
 * Only `"solution": "<old>"` inside a `{% uml %}` block is touched, by regex.
 * The JSON is never decoded and re-encoded: the author's JSON is stored
 * verbatim (.claude/rules/doc-uml.md), and a re-encode would reformat every
 * diagram it touched. Saved through the model, so the links re-sync and the
 * page records who renamed it.
 */
class RewriteSolutionBindings
{
    public function handle(string $old, string $new): void
    {
        if ($old === $new) {
            return;
        }

        $binding = '/("solution"\s*:\s*)"' . preg_quote($old, '/') . '"/';

        DocumentationPage::query()
            ->where('documentation', 'like', '%"' . $old . '"%')
            ->lazyById()
            ->each(function (DocumentationPage $page) use ($binding, $new) {
                $body = (string) preg_replace_callback(
                    '/^[ \t]*\{%\s*uml\b.*?^[ \t]*\{%\s*enduml\s*%\}/ms',
                    fn (array $block) => (string) preg_replace($binding, '$1"' . $new . '"', $block[0]),
                    (string) $page->documentation,
                );

                if ($body !== $page->documentation) {
                    $page->update(['documentation' => $body]);
                }
            });
    }
}
