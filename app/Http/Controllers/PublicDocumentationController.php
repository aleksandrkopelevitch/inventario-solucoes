<?php

namespace App\Http\Controllers;

use App\Contracts\Documentable;
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
 * doc da própria solução e a de cada integração em que ela participa, no estilo
 * GitBook (layout próprio `public-docs`, barra no topo + índice lateral).
 *
 * A mídia embutida (`/files/{id}` no Markdown) é reescrita para uma rota
 * pública dedicada (`public.docs.file`), validada contra a própria solução/
 * integrações — a rota autenticada `files.show` não serve visitantes.
 */
class PublicDocumentationController extends Controller
{
    public function solution(string $token): View
    {
        $solution = $this->resolve($token);

        return $this->page($solution, $solution, 'Solução');
    }

    public function integration(string $token, Integration $integration): View
    {
        $solution = $this->resolve($token);

        abort_unless($this->belongsToSolution($solution, $integration), 404);

        return $this->page($solution, $integration, 'Integração');
    }

    public function file(string $token, Media $media): BinaryFileResponse
    {
        $solution = $this->resolve($token);
        $owner = $media->model;

        $allowed = $media->collection_name === Documentable::DOCS_COLLECTION && (
            ($owner instanceof Solution && $owner->is($solution))
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

    private function page(Solution $solution, Documentable $current, string $eyebrow): View
    {
        $token = $solution->public_token;

        return view('public.docs', [
            'solution'     => $solution,
            'title'        => $current->documentationTitle(),
            'eyebrow'      => $eyebrow,
            'renderedHtml' => $this->render($current->documentation, $token),
            // Markdown cru para o botão "Copiar Markdown", com a mídia já
            // reescrita para as rotas públicas (o /files/{id} interno não
            // resolve para quem acessa só pelo link público).
            'markdown' => $this->rewriteFileUrls((string) $current->documentation, $token),
            'nav'      => $this->nav($solution, $current, $token),
        ]);
    }

    /** Renderiza o Markdown e reescreve `/files/{id}` para a rota pública. */
    private function render(?string $markdown, string $token): string
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
     * Índice lateral: a própria solução + cada integração em que participa.
     *
     * @return Collection<int, array{label: string, url: string, active: bool, hasDocs: bool}>
     */
    private function nav(Solution $solution, Documentable $current, string $token): Collection
    {
        $items = collect([[
            'label'   => $solution->name,
            'url'     => route('public.docs.solution', $token),
            'active'  => $current instanceof Solution,
            'hasDocs' => trim((string) $solution->documentation) !== '',
        ]]);

        return $items->concat(
            $solution->integrations()->get()->map(fn (Integration $integration) => [
                'label'   => $integration->name,
                'url'     => route('public.docs.integration', [$token, $integration]),
                'active'  => $current instanceof Integration && $current->is($integration),
                'hasDocs' => trim((string) $integration->documentation) !== '',
            ])
        );
    }
}
