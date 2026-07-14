<?php

namespace App\Services;

use App\Models\DocumentationPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Regras da árvore de páginas de documentação (lista plana, ordenada por
 * `position`), compartilhadas entre `SolutionDocumentationController` e
 * `DocumentationGroupPageController` — o container é polimórfico (`Solution`
 * ou `DocumentationGroup`), ambos expõem a mesma relação `pages()`.
 */
class DocumentationPageService
{
    /** Segmentos usados por rotas estáticas — nunca vira slug de página, senão colidiria com elas. */
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

    /** Só o título muda — o slug (e portanto a URL) permanece estável. */
    public function rename(DocumentationPage $page, string $title): void
    {
        $page->update(['title' => $title]);
    }

    /** Troca a posição da página com a vizinha (anterior ou seguinte) na lista ordenada. */
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

    /** Apaga a página (mídia embutida cai junto, via Spatie) e devolve a próxima página do container, se sobrar alguma. */
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
