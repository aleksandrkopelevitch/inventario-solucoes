<?php

namespace App\Support\Documentation;

use App\Models\Notebook;

/**
 * Where a documentation READER's links point.
 *
 * There are two read-only surfaces over the same page tree, and they differ in
 * nothing but their URLs:
 *
 * - the magic link (`public-docs/{token}/…`), no account at all;
 * - the internal knowledge base (`docs/{notebook}/…`), any Leo account.
 *
 * Everything else about them — the layout, the rail, the palette, the locks,
 * the child-page cards — is deliberately identical, which is why the shared
 * renderer (`App\Services\Documentation\DocumentationReader`) takes one of
 * these and knows nothing else about who is reading.
 *
 * `__INDEX__` in `secret()` is the placeholder the client substitutes for a
 * lock's ordinal, the same convention `NotebookPageController::edit()` uses:
 * a page has N locks and one endpoint shape, so the URL is built once with a
 * hole in it rather than N times.
 */
final class ReaderUrls
{
    private function __construct(
        private readonly Notebook $notebook,
        private readonly ?string $token,
    ) {}

    /** The magic link: everything is addressed by the caderno's public token. */
    public static function shared(Notebook $notebook, string $token): self
    {
        return new self($notebook, $token);
    }

    /** The internal knowledge base: addressed by the caderno itself. */
    public static function knowledgeBase(Notebook $notebook): self
    {
        return new self($notebook, null);
    }

    public function page(string $slug): string
    {
        return $this->token === null
            ? route('docs.page', [$this->notebook, $slug])
            : route('public.docs.page', [$this->token, $slug]);
    }

    public function file(int|string $mediaId): string
    {
        return $this->token === null
            ? route('docs.file', [$this->notebook, $mediaId])
            : route('public.docs.file', [$this->token, $mediaId]);
    }

    public function diagramPicture(string $slug): string
    {
        return $this->token === null
            ? route('docs.diagram', [$this->notebook, $slug])
            : route('public.docs.diagram', [$this->token, $slug]);
    }

    public function search(): string
    {
        return $this->token === null
            ? route('docs.search', $this->notebook)
            : route('public.docs.search', $this->token);
    }

    /** Where a lock on `$pageSlug` posts its code, `__INDEX__` standing in for the ordinal. */
    public function secret(string $pageSlug): string
    {
        return $this->token === null
            ? route('docs.secrets', [$this->notebook, $pageSlug, 'index' => '__INDEX__'])
            : route('public.docs.secrets', [$this->token, $pageSlug, 'index' => '__INDEX__']);
    }

    public function pageLinks(): PageLinks
    {
        return $this->token === null
            ? PageLinks::knowledgeBase($this->notebook)
            : PageLinks::shared($this->notebook, $this->token);
    }
}
