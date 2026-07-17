<?php

namespace App\Services\Flowspec;

use App\Enums\FlowspecTag;
use App\Models\DocumentationPage;
use App\Models\FlowspecExample;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves the context for a flowSpec generation without RAG: Solutions
 * cited (explicit ones take priority; otherwise, inferred by matching the
 * name in the request), documentation trimmed to a character budget —
 * Solution pages AND documentation of the integrations they participate
 * in —, and 2-3 corpus examples chosen via FlowspecTag's word->tag map.
 *
 * When the chat carries explicit `document_refs` (the "Specific documents"
 * chips picker), that automatic scoring/budget is skipped entirely: exactly
 * the chosen pages/integrations are used — the idea is to give someone who
 * already knows exactly which documentation is relevant a way to avoid
 * automatic inference, which can pull in unnecessary context.
 *
 * `suggestDocumentsFor()` (called by FlowspecGenerationService only when the
 * model's response was conversational — a question, not a flowSpec) is the
 * inverse side: it finds REAL documentation for systems the model cited by
 * name while asking for more context, but that weren't already in the
 * considered context — becomes an "add" button in the chat instead of the
 * user having to type into the chips picker. Never invents a name: matches
 * against the existing Solution catalog, same as inferSolutions().
 */
class FlowspecContextResolver
{
    /**
     * @param  list<int>  $solutionIds  ids explicitly marked in the chat
     * @param  list<array{type: string, id: int}>  $documentRefs  pages/integrations chosen by hand
     */
    public function resolve(string $request, array $solutionIds = [], array $documentRefs = []): FlowspecContext
    {
        $normalizedRequest = $this->normalize($request);

        $solutions = $solutionIds !== []
            ? Solution::query()->whereIn('id', $solutionIds)->get()
            : $this->inferSolutions($normalizedRequest);

        [$pages, $integrationDocs, $omitted] = $documentRefs !== []
            ? $this->selectExplicitDocuments($documentRefs)
            : $this->selectDocuments($solutions, $normalizedRequest);

        $tags = $this->candidateTags($normalizedRequest);

        return new FlowspecContext(
            solutions: $solutions,
            pages: $pages,
            integrationDocs: $integrationDocs,
            omittedDocuments: $omitted,
            examples: $this->selectExamples($tags),
            tags: $tags,
        );
    }

    /**
     * Without an explicit selection, suggests Solutions whose name appears in
     * the request ("based on SVL's and IAM's documentation...").
     *
     * The matching (accent-insensitive, by word-boundary) runs in PHP, not
     * SQL: `Str::ascii()` folds "ó"->"o" in a way that's portable between
     * SQLite (dev) and PostgreSQL (prod) without depending on an extension
     * (`unaccent`) or a driver-specific collation — porting this to SQL would
     * trade portability/correctness for speed, with no real need at the
     * catalog's current scale (dozens of Solutions, a single query).
     *
     * @return Collection<int, Solution>
     */
    private function inferSolutions(string $normalizedRequest): Collection
    {
        return Solution::query()
            ->get(['id', 'name'])
            ->filter(function (Solution $solution) use ($normalizedRequest) {
                $name = $this->normalize($solution->name);

                return $name !== ''
                    && preg_match('/(?<![a-z0-9])' . preg_quote($name, '/') . '(?![a-z0-9])/', $normalizedRequest) === 1;
            })
            ->values();
    }

    /**
     * Documentation for Solutions the model cited by name in a conversational
     * response ("I need to know how IAM authenticates…") but that weren't
     * already in `$consideredSolutions` — each page/integration found becomes
     * an "add" button in the chat (same `{type, id}` reference as the
     * "Specific documents" chips picker, so the click reuses the addChip()
     * already existing in chips.js). Reuses inferSolutions()'s matching —
     * never suggests a name the model couldn't have seen somewhere real in
     * the catalog.
     *
     * @param  Collection<int, Solution>  $consideredSolutions
     * @return list<array{type: string, id: int, label: string}>
     */
    public function suggestDocumentsFor(string $text, Collection $consideredSolutions): array
    {
        // pluck('id'), not modelKeys(): unlike $mentioned (always coming
        // from inferSolutions(), a real Eloquent\Collection),
        // $consideredSolutions is the method's public parameter — it accepts
        // any Collection<Solution>, not just Eloquent\Collection.
        $consideredIds = $consideredSolutions->pluck('id')->all();

        $mentioned = $this->inferSolutions($this->normalize($text))
            ->reject(fn (Solution $solution) => in_array($solution->id, $consideredIds, true))
            ->values();

        if ($mentioned->isEmpty()) {
            return [];
        }

        // Only the columns used in the labels — doesn't pull the longText
        // `documentation` (it's only used in the WHERE), per conversational message.
        $pages = DocumentationPage::query()
            ->where('container_type', Solution::class)
            ->whereIn('container_id', $mentioned->modelKeys())
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->orderBy('position')
            ->get(['id', 'container_id', 'title']);

        $solutionsById = $mentioned->keyBy->getKey();

        $integrations = Integration::query()
            ->whereHas('participants', fn ($query) => $query->whereIn('solutions.id', $mentioned->modelKeys()))
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->get(['id', 'name']);

        // collect($model->all()) before ->map() — same care as the ->merge()
        // in selectDocuments(): mapping directly on an empty Eloquent\Collection
        // doesn't downgrade it to Support\Collection, and Eloquent's
        // primary-key merge breaks against a plain array.
        $pageSuggestions = collect($pages->all())->map(fn (DocumentationPage $page) => [
            'type'  => 'page',
            'id'    => $page->id,
            'label' => "{$solutionsById[$page->container_id]->name} — {$page->title}",
        ]);

        $integrationSuggestions = collect($integrations->all())->map(fn (Integration $integration) => [
            'type'  => 'integration',
            'id'    => $integration->id,
            'label' => $integration->name,
        ]);

        $limit = (int) config('services.flowspec.max_suggested_documents');

        return $pageSuggestions->merge($integrationSuggestions)->take($limit)->values()->all();
    }

    /**
     * Documented pages of the chosen Solutions + documentation of the
     * integrations they participate in, ordered by matches against terms in
     * the request (contracts, payloads, endpoints cited) and trimmed together
     * to the same character budget. On a relevance tie, integration docs come
     * before pages (for a flowSpec, it's the more direct source). What's left
     * out comes back in `omitted` to be flagged in the chat.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array{Collection<int, DocumentationPage>, Collection<int, Integration>, list<array{type: string, id: int, label: string}>}
     */
    private function selectDocuments(Collection $solutions, string $normalizedRequest): array
    {
        if ($solutions->isEmpty()) {
            return [collect(), collect(), []];
        }

        $terms = $this->significantTerms($normalizedRequest);

        $pages = DocumentationPage::query()
            ->where('container_type', Solution::class)
            ->whereIn('container_id', $solutions->modelKeys())
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->orderBy('position')
            ->get();

        $solutionsById = $solutions->keyBy->getKey();
        $pages->each(fn (DocumentationPage $page) => $page->setRelation('container', $solutionsById[$page->container_id]));

        $integrations = Integration::query()
            ->whereHas('participants', fn ($query) => $query->whereIn('solutions.id', $solutions->modelKeys()))
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->get();

        // collect($model->all()) forces a "pure" Support\Collection before
        // ->map() — mapping directly on an Eloquent\Collection would return
        // another Eloquent\Collection (even with arrays inside), and the
        // ->merge() below would use Eloquent's primary-key dictionary merge
        // (getKey()), which breaks against a plain array.
        $units = collect($pages->all())
            ->map(fn (DocumentationPage $page) => ['kind' => 'page', 'model' => $page, 'heading' => $page->title, 'body' => $page->documentation])
            ->merge(collect($integrations->all())->map(fn (Integration $integration) => ['kind' => 'integration', 'model' => $integration, 'heading' => $integration->name, 'body' => $integration->documentation]));

        $scored = $units
            ->map(function (array $unit) use ($terms) {
                $haystack = $this->normalize($unit['heading'] . ' ' . $unit['body']);
                $unit['score'] = collect($terms)->filter(fn (string $term) => str_contains($haystack, $term))->count();

                return $unit;
            })
            // Term relevance rules (score * 2). Tie on score: INTEGRATION docs
            // come before pages — for generating a flowSpec (which is itself
            // the integration's description: endpoints, contracts, protocols),
            // integration documentation is the more direct source. It's only a
            // tiebreaker: a page clearly more relevant to the request still
            // beats a barely relevant integration.
            ->sortByDesc(fn (array $unit) => $unit['score'] * 2 + ($unit['kind'] === 'integration' ? 1 : 0))
            ->values();

        $budget = (int) config('services.flowspec.doc_budget_chars');
        $selected = collect();
        $omitted = [];

        foreach ($scored as $unit) {
            $size = mb_strlen($unit['body']);

            if ($selected->isNotEmpty() && $budget - $size < 0) {
                $omitted[] = ['type' => $unit['kind'], 'id' => $unit['model']->getKey(), 'label' => $unit['heading']];

                continue;
            }

            $budget -= $size;
            $selected->push($unit);
        }

        // Re-presents each type in its natural order, not the score order.
        $selectedPages = $selected->where('kind', 'page')->pluck('model')
            ->sortBy(fn (DocumentationPage $page) => [$page->container_id, $page->position])->values();

        $selectedIntegrations = $selected->where('kind', 'integration')->pluck('model')
            ->sortBy(fn (Integration $integration) => $integration->name)->values();

        return [$selectedPages, $selectedIntegrations, $omitted];
    }

    /**
     * Context chosen by hand via the chips picker — no scoring or budget
     * trimming: whatever was selected goes into the prompt in full.
     *
     * @param  list<array{type: string, id: int}>  $documentRefs
     * @return array{Collection<int, DocumentationPage>, Collection<int, Integration>, list<array{type: string, id: int, label: string}>}
     */
    private function selectExplicitDocuments(array $documentRefs): array
    {
        $refs = collect($documentRefs);
        $pageIds = $refs->where('type', 'page')->pluck('id')->all();
        $integrationIds = $refs->where('type', 'integration')->pluck('id')->all();

        $pages = $pageIds === [] ? collect() : DocumentationPage::query()
            ->whereIn('id', $pageIds)
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->with('container')
            ->get()
            ->sortBy(fn (DocumentationPage $page) => [$page->container_id, $page->position])
            ->values();

        $integrations = $integrationIds === [] ? collect() : Integration::query()
            ->whereIn('id', $integrationIds)
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->get()
            ->sortBy('name')
            ->values();

        return [$pages, $integrations, []];
    }

    /** @return list<string> */
    private function candidateTags(string $normalizedRequest): array
    {
        $tags = [];

        foreach (FlowspecTag::cases() as $tag) {
            foreach ($tag->keywords() as $keyword) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($keyword, '/') . '(?![a-z0-9])/', $normalizedRequest) === 1) {
                    $tags[] = $tag->value;

                    break;
                }
            }
        }

        return $tags;
    }

    /**
     * The 2-3 examples with the most tags in common with the request — more
     * than that dilutes the signal and wastes tokens. Fallback: the generic
     * anchor example.
     *
     * @param  list<string>  $tags
     * @return Collection<int, FlowspecExample>
     */
    private function selectExamples(array $tags): Collection
    {
        $limit = (int) config('services.flowspec.max_examples');

        $examples = $tags === []
            ? collect()
            : FlowspecExample::query()->active()->withAnyTag($tags)->get()
                ->sortByDesc(fn (FlowspecExample $example) => count(array_intersect($example->tags, $tags)))
                ->take($limit)
                ->values();

        if ($examples->isEmpty()) {
            $examples = FlowspecExample::query()
                ->active()
                ->where('slug', config('services.flowspec.fallback_example'))
                ->get();
        }

        return $examples;
    }

    /** Lowercase and unaccented, to match keyword and Solution name. */
    private function normalize(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }

    /** @return list<string> unique words from the request with 4+ characters */
    private function significantTerms(string $normalizedRequest): array
    {
        preg_match_all('/[a-z0-9][a-z0-9_-]{3,}/', $normalizedRequest, $matches);

        return array_values(array_unique($matches[0]));
    }
}
