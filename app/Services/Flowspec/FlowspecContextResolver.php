<?php

namespace App\Services\Flowspec;

use App\Enums\FlowspecTag;
use App\Models\DocumentationPage;
use App\Models\FlowspecExample;
use App\Models\Solution;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolve o contexto de uma geração de flowSpec sem RAG: Solutions citadas
 * (explícitas têm prioridade; senão, inferidas casando o nome no pedido),
 * páginas de documentação recortadas ao orçamento de caracteres, e 2-3
 * exemplos do corpus escolhidos pelo mapa palavra->tag do FlowspecTag.
 */
class FlowspecContextResolver
{
    /**
     * @param  list<int>  $solutionIds  ids marcados explicitamente no chat
     */
    public function resolve(string $request, array $solutionIds = []): FlowspecContext
    {
        $normalizedRequest = $this->normalize($request);

        $solutions = $solutionIds !== []
            ? Solution::query()->whereIn('id', $solutionIds)->get()
            : $this->inferSolutions($normalizedRequest);

        [$pages, $omitted] = $this->selectPages($solutions, $normalizedRequest);

        $tags = $this->candidateTags($normalizedRequest);

        return new FlowspecContext(
            solutions: $solutions,
            pages: $pages,
            omittedPages: $omitted,
            examples: $this->selectExamples($tags),
            tags: $tags,
        );
    }

    /**
     * Sem seleção explícita, sugere Solutions cujo nome aparece no pedido
     * ("com base na documentação do SVL e do IAM...").
     *
     * O casamento (acento-insensível, por word-boundary) roda em PHP, não em
     * SQL: `Str::ascii()` dobra "ó"->"o" de um jeito portável entre SQLite
     * (dev) e PostgreSQL (prod) sem depender de extensão (`unaccent`) ou
     * collation específica de um dos dois drivers — uma tradução pra SQL
     * ganharia velocidade trocando portabilidade/corretude, sem necessidade
     * real na escala atual do catálogo (dezenas de Solutions, uma query só).
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
     * Páginas documentadas das Solutions escolhidas, priorizadas pelas que
     * casam termos do pedido (contratos, payloads, endpoints citados) e
     * cortadas ao orçamento de caracteres — o que ficou de fora volta em
     * `omittedPages` para ser sinalizado no chat.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array{Collection<int, DocumentationPage>, list<string>}
     */
    private function selectPages(Collection $solutions, string $normalizedRequest): array
    {
        if ($solutions->isEmpty()) {
            return [collect(), []];
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

        $scored = $pages->sortByDesc(function (DocumentationPage $page) use ($terms) {
            $haystack = $this->normalize($page->title . ' ' . $page->documentation);

            return collect($terms)->filter(fn (string $term) => str_contains($haystack, $term))->count();
        })->values();

        $budget = (int) config('services.flowspec.doc_budget_chars');
        $selected = collect();
        $omitted = [];

        foreach ($scored as $page) {
            $size = mb_strlen($page->documentation);

            if ($selected->isNotEmpty() && $budget - $size < 0) {
                $omitted[] = $page->title;

                continue;
            }

            $budget -= $size;
            $selected->push($page);
        }

        // Reapresenta na ordem natural (solução, posição), não na ordem do score.
        $selected = $selected->sortBy(fn (DocumentationPage $page) => [$page->container_id, $page->position])->values();

        return [$selected, $omitted];
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
     * Os 2-3 exemplos com mais tags em comum com o pedido — mais que isso
     * dilui o sinal e gasta token à toa. Fallback: o exemplo âncora genérico.
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

    /** Minúsculas e sem acento, para casar palavra-chave e nome de Solution. */
    private function normalize(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }

    /** @return list<string> palavras do pedido com 4+ caracteres, únicas */
    private function significantTerms(string $normalizedRequest): array
    {
        preg_match_all('/[a-z0-9][a-z0-9_-]{3,}/', $normalizedRequest, $matches);

        return array_values(array_unique($matches[0]));
    }
}
