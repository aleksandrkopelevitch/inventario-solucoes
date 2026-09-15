<?php

namespace App\Support\Documentation;

use App\Models\Notebook;

/**
 * Resolves the `page:` links a documentation page writes into the URL the
 * READER of that page should get.
 *
 * A link to another page of the same caderno is authored as an ordinary
 * Markdown link with a destination of `page:{slug}` (optionally `#anchor`) —
 * the fifth construct-by-convention of the dialect, and the only one that is a
 * plain link rather than a `{% … %}` block, because that is what keeps both
 * parsers (`GitbookRenderer` and `docs-markdown.js`), Editor.js's link tool and
 * "Copiar Markdown" working with no change at all.
 *
 * Addressed by SLUG for the same reasons `{% diagram %}` is: it is what the
 * author picked and what the URL shows, it survives a database reload between
 * environments, and a citation that outlives its page still reads as something.
 * What it is NOT is a URL — and that is the whole point of this class. The same
 * page is reachable at THREE completely different addresses:
 *
 * - `notebooks/{notebook}/{page}` for somebody editing it;
 * - `docs/{notebook}/{page}` for anybody at Leo reading the knowledge base;
 * - `public-docs/{token}/page/{slug}` for a visitor holding the magic link.
 *
 * Writing any one of them into the Markdown would make the text correct for
 * exactly one audience — a shared caderno full of links to a login screen, or
 * an internal page full of links carrying a token. Resolving at render time is
 * also what keeps a link alive when the caderno is renamed.
 *
 * Scoped to ONE caderno, deliberately. The public reader can only answer for
 * the caderno its token grants, so a link that could point outside it would be
 * a link that works while you edit and 404s the moment you share — see
 * `PublicDocumentationController`. The knowledge base keeps the same scope even
 * though it COULD address another caderno: a link that resolves only while the
 * caderno happens to be published is a link that silently dies when an admin
 * unpublishes the other one, and "the link works for some readers" is the
 * failure this class exists to avoid.
 *
 * The map is built LAZILY: most pages contain no internal link at all, and this
 * is constructed on every render.
 */
final class PageLinks
{
    private const EDITOR = 'editor';

    private const KNOWLEDGE_BASE = 'knowledge-base';

    private const SHARED = 'shared';

    /** @var array<string, string>|null slug => url, null until the first lookup */
    private ?array $urls = null;

    private function __construct(
        private readonly ?Notebook $notebook,
        private readonly string $surface = self::EDITOR,
        private readonly ?string $token = null,
    ) {}

    /**
     * No caderno to resolve against — every `page:` link renders as dead text.
     *
     * Used by the search index (which reads the rendered HTML for anchors and
     * text, never for links) and by the flowSpec thread, which renders chat
     * Markdown that has no page behind it.
     */
    public static function none(): self
    {
        return new self(null);
    }

    /** Links as the signed-in app addresses them, in the page editor. */
    public static function internal(Notebook $notebook): self
    {
        return new self($notebook, self::EDITOR);
    }

    /** Links as the internal knowledge base (`/docs`) addresses them. */
    public static function knowledgeBase(Notebook $notebook): self
    {
        return new self($notebook, self::KNOWLEDGE_BASE);
    }

    /** Links as a visitor holding the caderno's magic link addresses them. */
    public static function shared(Notebook $notebook, string $token): self
    {
        return new self($notebook, self::SHARED, $token);
    }

    /** The URL for a page of this caderno, or null when there is no such page. */
    public function urlFor(string $slug): ?string
    {
        if ($this->notebook === null) {
            return null;
        }

        $this->urls ??= $this->build();

        return $this->urls[$slug] ?? null;
    }

    /** @return array<string, string> */
    private function build(): array
    {
        return $this->notebook->pages()
            ->get(['id', 'notebook_id', 'slug'])
            ->mapWithKeys(fn ($page): array => [$page->slug => match ($this->surface) {
                self::SHARED         => route('public.docs.page', [$this->token, $page->slug]),
                self::KNOWLEDGE_BASE => route('docs.page', [$this->notebook, $page->slug]),
                default              => route('notebooks.pages.edit', [$this->notebook, $page->slug]),
            }])
            ->all();
    }
}
