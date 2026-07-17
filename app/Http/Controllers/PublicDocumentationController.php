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
 * PUBLIC documentation for a solution ("magic link"), no auth. Access is via
 * an opaque token in the URL (`Solution::public_token`); from it, shows the
 * solution's own page tree (1..N, GitBook-style) and the docs for each
 * integration it participates in, GitBook-style (dedicated `public-docs`
 * layout, top bar + side index). Standalone Groups ("Nestings") are never
 * exposed here — only the Solution's own tree.
 *
 * Embedded media (`/files/{id}` in the Markdown) is rewritten to a dedicated
 * public route (`public.docs.file`), validated against the solution/
 * integrations themselves — the authenticated `files.show` route doesn't
 * serve visitors.
 */
class PublicDocumentationController extends Controller
{
    /** First page of the tree (or none, if the solution has no page yet). */
    public function solution(string $token): View
    {
        $solution = $this->resolve($token);

        return $this->render($solution, $solution->pages()->first(), 'Solução');
    }

    /**
     * `$slug` is NOT resolved via route-model-binding — a DocumentationPage's
     * slug is only unique WITHIN its container (see the composite unique on
     * `documentation_pages`), never globally. Two solutions can each have a
     * page called "test"; a global `{page:slug}` binding would grab the
     * lowest id (belonging to another solution) and 404 for the wrong owner.
     * Scoping the query by the Solution resolved from the token avoids the
     * ambiguity entirely.
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
            // Raw Markdown for the "Copiar Markdown" button, with media already
            // rewritten to the public routes (the internal /files/{id} does
            // not resolve for visitors accessing only via the public link).
            'markdown' => $this->rewriteFileUrls((string) $markdown, $token),
            'nav'      => $this->nav($solution, $current, $token),
        ]);
    }

    /** Renders the Markdown and rewrites `/files/{id}` to the public route. */
    private function renderMarkdown(?string $markdown, string $token): string
    {
        return $this->rewriteFileUrls(app(GitbookRenderer::class)->render($markdown), $token);
    }

    /** Rewrites every `src|href="/files/{id}"` to the public `public.docs.file` route. */
    private function rewriteFileUrls(string $content, string $token): string
    {
        return preg_replace_callback(
            '#(src|href)="/files/(\d+)"#',
            fn (array $m) => $m[1] . '="' . route('public.docs.file', [$token, $m[2]]) . '"',
            $content,
        );
    }

    /**
     * Side index: every page in the solution's tree + every integration it
     * participates in.
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
