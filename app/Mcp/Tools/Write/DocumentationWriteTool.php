<?php

namespace App\Mcp\Tools\Write;

use App\Enums\AccessModule;
use App\Mcp\Actor;
use App\Mcp\Support\Presenter;
use App\Mcp\Support\ReadableNotebooks;
use App\Mcp\ToolResult;
use App\Mcp\Tools\GetNotebook;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Services\DocumentationPageService;

/**
 * What the caderno and page write tools share: finding the caderno and the
 * page the way the readers do, resolving solution slugs, and answering in the
 * readers' own shapes.
 */
abstract class DocumentationWriteTool extends WriteTool
{
    public function __construct(
        protected readonly ReadableNotebooks $notebooks,
        protected readonly GetNotebook $notebookReader,
        protected readonly DocumentationPageService $pages,
        protected readonly Presenter $presenter,
    ) {}

    public function module(): ?AccessModule
    {
        return AccessModule::Documentation;
    }

    /** The caderno, or the failure to answer with. A writing connection reads every caderno (ReadableNotebooks). */
    protected function notebook(string $slug, Actor $actor): Notebook|ToolResult
    {
        return $this->notebooks->find($slug, $actor)
            ?? ToolResult::failure($this->notebooks->notFound($slug));
    }

    /** A page of THIS caderno — a page slug is unique per caderno, never across them. */
    protected function page(Notebook $notebook, string $slug): DocumentationPage|ToolResult
    {
        $page = $notebook->pages()->where('slug', $slug)->first();

        if (! $page) {
            return ToolResult::failure(
                "O caderno \"{$notebook->name}\" não tem nenhuma página com o slug \"{$slug}\". "
                . 'Use get_notebook para ver a árvore de páginas.',
            );
        }

        // In hand already: the policy and the tree helpers would otherwise
        // lazy-load it (§ Strict mode).
        $page->setRelation('notebook', $notebook);

        return $page;
    }

    /**
     * Solution slugs → ids, or the slugs that match nothing.
     *
     * @return array{ids: list<int>, missing: list<string>}
     */
    protected function solutionIds(mixed $slugs): array
    {
        $slugs = collect(is_array($slugs) ? $slugs : [$slugs])
            ->filter(fn ($slug) => is_scalar($slug) && trim((string) $slug) !== '')
            ->map(fn ($slug) => trim((string) $slug))
            ->unique()
            ->values();

        $found = Solution::query()->whereIn('slug', $slugs->all())->pluck('id', 'slug');

        return [
            'ids'     => $found->values()->all(),
            'missing' => $slugs->reject(fn (string $slug) => $found->has($slug))->values()->all(),
        ];
    }

    protected function notebookAnswer(Notebook $notebook, string $message): ToolResult
    {
        return ToolResult::json([
            'message'  => $message,
            'notebook' => $this->notebookReader->present($notebook->refresh()->load('solutions:id,slug,name')),
        ]);
    }

    /** The page's place and `version` — what `update_page` must send back next. */
    protected function pageAnswer(DocumentationPage $page, Notebook $notebook, string $message): ToolResult
    {
        $page->refresh();

        return ToolResult::json([
            'message' => $message,
            'page'    => [
                ...$this->presenter->pageSummary($page, $notebook, $page->depth()),
                'parent'      => $page->parent()->value('slug'),
                'position'    => $this->pages->indexAmongSiblings($page),
                'has_content' => $page->hasContent(),
                'version'     => $page->version(),
            ],
        ]);
    }
}
