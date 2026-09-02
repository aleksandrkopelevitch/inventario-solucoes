<?php

namespace App\Services\Documentation;

use App\Models\DocumentationPage;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\SecretText;

/**
 * Resolves the OTHER documentation pages a person chose as context for one
 * Documentation Assistant turn into text the prompt can carry.
 *
 * It exists beside `ContextDocumentResolver` rather than inside it because the
 * two answer different questions. An uploaded context document is material
 * somebody brought from outside — a spec, a pipeline JSON, a PDF that goes to
 * the model as a native attachment — and it belongs to the caderno. A context
 * PAGE is documentation this app already holds, it is always text, and it is
 * regularly in ANOTHER caderno: the page most worth showing while documenting
 * an integration is the one describing the system on the other end of it.
 *
 * Three things happen to a page's text on the way in, and each is the same rule
 * the rest of the module keeps:
 *
 * - **Protected values are masked** (`SecretText`). This is the fifth surface
 *   that hands a page's text to somebody, after the read-only "Copiar
 *   Markdown", the public render, the assistant's own prompt and flowSpec's —
 *   and it is the one place where the page being read is not the page being
 *   edited, so a reader who may not see a value in caderno A could otherwise
 *   have the model quote it into caderno B.
 * - **Media, file, embed and diagram blocks are STRIPPED**, not frozen — see
 *   `BlockVault::strip()`. They belong to another page; the model must not be
 *   shown syntax it could copy into this one.
 * - **Opaque literals are vaulted by the caller** (`LiteralVault`, in
 *   `DocumentationChatService`), which is why this returns the text rather than
 *   the finished prompt section: one vault has to cover every text in the turn
 *   so the same value gets the same marker wherever it appears.
 */
class ContextPageResolver
{
    /**
     * @param  list<int>  $pageIds  in the order the person picked them
     */
    public function resolve(array $pageIds, ?DocumentationPage $exclude = null): ContextPageSet
    {
        $ids = array_values(array_unique(array_map(intval(...), $pageIds)));

        if ($ids === []) {
            return new ContextPageSet(collect(), []);
        }

        $max = (int) config('services.documentation_ai.max_context_pages');
        $budget = (int) config('services.documentation_ai.page_budget_chars');

        // The page being written is never its own reference: its content is
        // already in the prompt as "CONTEÚDO ATUAL DA PÁGINA", and a second
        // copy under another heading is how a model ends up unsure which of the
        // two it is meant to be rewriting.
        $pages = DocumentationPage::query()
            ->with('notebook:id,name')
            ->whereIn('id', $ids)
            ->when($exclude !== null, fn ($query) => $query->whereKeyNot($exclude->getKey()))
            ->get()
            // Back into the order they were picked in: `whereIn` answers in
            // whatever order the database likes, and the person's order is the
            // only one that means anything here.
            ->sortBy(fn (DocumentationPage $page): int => array_search($page->id, $ids, true))
            ->values();

        $selected = $pages->take($max);
        $omitted = $pages->slice($max)->pluck('title')->values()->all();

        $resolved = collect();

        foreach ($selected as $page) {
            $content = trim(BlockVault::strip(SecretText::mask($page->documentation)));

            if ($content === '') {
                continue;
            }

            if ($budget <= 0) {
                $omitted[] = $page->title;

                continue;
            }

            $truncated = mb_strlen($content) > $budget;
            if ($truncated) {
                $content = mb_substr($content, 0, $budget) . "\n\n[página truncada]";
            }

            $budget -= mb_strlen($content);

            $resolved->push([
                'title'     => $page->title,
                'notebook'  => $page->notebook?->name ?? '',
                'content'   => $content,
                'truncated' => $truncated,
            ]);
        }

        return new ContextPageSet($resolved, $omitted);
    }
}
