<?php

namespace App\Http\Controllers;

use App\Contracts\Documentable;
use App\Models\DocumentationPage;
use App\Models\Integration;
use App\Models\Solution;
use App\Support\GitbookRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Documentação PÚBLICA de uma solução ("magic link"), sem auth. O acesso é por
 * um token opaco na URL (`Solution::public_token`); a partir dele mostra-se a
 * árvore de páginas da própria solução (1..N, GitBook-style) e a doc de cada
 * integração em que ela participa, no estilo GitBook (layout próprio
 * `public-docs`, barra no topo + índice lateral). Grupos ("Aninhamentos")
 * standalone nunca são expostos aqui — só a árvore da própria Solution.
 *
 * A mídia embutida (`/files/{id}` no Markdown) é reescrita para uma rota
 * pública dedicada (`public.docs.file`), validada contra a própria solução/
 * integrações — a rota autenticada `files.show` não serve visitantes.
 */
class PublicDocumentationController extends Controller
{
    /** Primeira página da árvore (ou nenhuma, se a solução ainda não tem página). */
    public function solution(string $token): View
    {
        $solution = $this->resolve($token);

        return $this->render($solution, $solution->pages()->first(), 'Solução');
    }

    /**
     * `$slug` NÃO é resolvido por route-model-binding — o slug de uma
     * DocumentationPage só é único DENTRO do seu container (ver unique
     * composto em `documentation_pages`), nunca globalmente. Duas soluções
     * podem ter cada uma uma página "teste"; um binding `{page:slug}` global
     * pegaria a de menor id (a de outra solução) e 404aria por dono errado.
     * Escopar a query pela própria Solution resolvida do token evita a
     * ambiguidade de vez.
     */
    public function page(string $token, string $slug): View
    {
        $solution = $this->resolve($token);
        $page = $solution->pages()->where('slug', $slug)->firstOrFail();

        return $this->render($solution, $page, 'Solução');
    }

    public function integration(string $token, Integration $integration): View
    {
        $solution = $this->resolve($token);

        abort_unless($this->belongsToSolution($solution, $integration), 404);

        return $this->render($solution, $integration, 'Integração');
    }

    public function file(string $token, Media $media): BinaryFileResponse
    {
        $solution = $this->resolve($token);
        $owner = $media->model;

        $allowed = $media->collection_name === Documentable::DOCS_COLLECTION && (
            ($owner instanceof DocumentationPage && $this->belongsToSolutionPages($solution, $owner))
            || ($owner instanceof Integration && $this->belongsToSolution($solution, $owner))
        );

        abort_unless($allowed, 404);

        return response()->file($media->getPath(), [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }

    private function resolve(string $token): Solution
    {
        return Solution::where('public_token', $token)->firstOrFail();
    }

    private function belongsToSolution(Solution $solution, Integration $integration): bool
    {
        return $solution->integrations()->whereKey($integration->getKey())->exists();
    }

    private function belongsToSolutionPages(Solution $solution, DocumentationPage $page): bool
    {
        return $page->container_type === Solution::class && (int) $page->container_id === $solution->id;
    }

    private function render(Solution $solution, ?Documentable $current, string $eyebrow): View
    {
        $token = $solution->public_token;
        $markdown = $current?->documentation;

        return view('public.docs', [
            'solution'     => $solution,
            'title'        => $current?->documentationTitle() ?? $solution->name,
            'eyebrow'      => $eyebrow,
            'renderedHtml' => $this->renderMarkdown($markdown, $token),
            // Markdown cru para o botão "Copiar Markdown", com a mídia já
            // reescrita para as rotas públicas (o /files/{id} interno não
            // resolve para quem acessa só pelo link público).
            'markdown' => $this->rewriteFileUrls((string) $markdown, $token),
            'nav'      => $this->nav($solution, $current, $token),
        ]);
    }

    /** Renderiza o Markdown e reescreve `/files/{id}` para a rota pública. */
    private function renderMarkdown(?string $markdown, string $token): string
    {
        return $this->rewriteFileUrls(app(GitbookRenderer::class)->render($markdown), $token);
    }

    /** Reescreve todo `src|href="/files/{id}"` para a rota pública `public.docs.file`. */
    private function rewriteFileUrls(string $content, string $token): string
    {
        return preg_replace_callback(
            '#(src|href)="/files/(\d+)"#',
            fn (array $m) => $m[1] . '="' . route('public.docs.file', [$token, $m[2]]) . '"',
            $content,
        );
    }

    /**
     * Índice lateral: cada página da árvore da solução + cada integração em
     * que ela participa.
     *
     * @return Collection<int, array{label: string, url: string, active: bool, hasDocs: bool}>
     */
    private function nav(Solution $solution, ?Documentable $current, string $token): Collection
    {
        $pages = $solution->pages()->get()->map(fn (DocumentationPage $page) => [
            'label'   => $page->title,
            'url'     => route('public.docs.page', [$token, $page]),
            'active'  => $current instanceof DocumentationPage && $current->is($page),
            'hasDocs' => trim((string) $page->documentation) !== '',
        ]);

        $integrations = $solution->integrations()->get()->map(fn (Integration $integration) => [
            'label'   => $integration->name,
            'url'     => route('public.docs.integration', [$token, $integration]),
            'active'  => $current instanceof Integration && $current->is($integration),
            'hasDocs' => trim((string) $integration->documentation) !== '',
        ]);

        return $pages->concat($integrations);
    }
}
