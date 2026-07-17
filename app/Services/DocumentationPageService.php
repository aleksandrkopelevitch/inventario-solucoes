<?php

namespace App\Services;

use App\Models\DocumentationPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Rules for the documentation page tree (flat list, ordered by `position`),
 * shared between `SolutionDocumentationController` and
 * `DocumentationGroupPageController` — the container is polymorphic
 * (`Solution` or `DocumentationGroup`), both expose the same `pages()` relation.
 */
class DocumentationPageService
{
    /** Segments used by static routes — never becomes a page slug, or it would collide with them. */
    private const RESERVED_SLUGS = ['paginas', 'titulo', 'mover', 'midia', 'compartilhar'];

    public function create(Model $container, string $title): DocumentationPage
    {
        $position = (int) $container->pages()->max('position') + 1;

        return $container->pages()->create([
            'title'    => $title,
            'slug'     => $this->uniqueSlug($container, $title),
            'position' => $position,
        ]);
    }

    /** Only the title changes — the slug (and therefore the URL) stays stable. */
    public function rename(DocumentationPage $page, string $title): void
    {
        $page->update(['title' => $title]);
    }

    /** Swaps the page's position with its neighbor (previous or next) in the ordered list. */
    public function move(DocumentationPage $page, string $direction): void
    {
        $pages = $page->container->pages()->get();
        $index = $pages->search(fn (DocumentationPage $p) => $p->is($page));
        $neighbor = $pages->get($direction === 'up' ? $index - 1 : $index + 1);

        if (! $neighbor) {
            return;
        }

        [$pagePosition, $neighborPosition] = [$page->position, $neighbor->position];
        $page->update(['position' => $neighborPosition]);
        $neighbor->update(['position' => $pagePosition]);
    }

    /** Deletes the page (embedded media goes with it, via Spatie) and returns the container's next page, if any remain. */
    public function delete(DocumentationPage $page): ?DocumentationPage
    {
        $container = $page->container;
        $page->delete();

        return $container->pages()->first();
    }

    private function uniqueSlug(Model $container, string $title): string
    {
        $base = Str::slug($title) ?: 'pagina';
        $slug = $base;
        $suffix = 1;

        while (in_array($slug, self::RESERVED_SLUGS, true) || $container->pages()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
