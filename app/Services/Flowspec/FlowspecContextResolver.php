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
 * Resolve o contexto de uma geração de flowSpec sem RAG: Solutions citadas
 * (explícitas têm prioridade; senão, inferidas casando o nome no pedido),
 * documentação recortada ao orçamento de caracteres — páginas das Solutions
 * E documentação das integrações em que elas participam —, e 2-3 exemplos
 * do corpus escolhidos pelo mapa palavra->tag do FlowspecTag.
 *
 * Quando o chat carrega `document_refs` explícitos (chips picker "Documentos
 * específicos"), esse scoring/orçamento automático é pulado inteiramente:
 * usa-se exatamente as páginas/integrações escolhidas — a ideia é dar a
 * quem sabe exatamente qual documentação é relevante uma forma de evitar a
 * inferência automática, que pode incluir contexto desnecessário.
 *
 * `suggestDocumentsFor()` (chamado por FlowspecGenerationService só quando a
 * resposta do modelo foi conversacional — uma pergunta, não um flowSpec)
 * é o lado inverso: acha documentação REAL para sistemas que o modelo citou
 * pelo nome ao pedir mais contexto, mas que ainda não estavam no contexto
 * considerado — vira botão de "adicionar" no chat em vez do usuário ter que
 * digitar no chips picker. Nunca inventa um nome: casa contra o catálogo de
 * Solutions existente, igual a inferSolutions().
 */
class FlowspecContextResolver
{
    /**
     * @param  list<int>  $solutionIds  ids marcados explicitamente no chat
     * @param  list<array{type: string, id: int}>  $documentRefs  páginas/integrações escolhidas na mão
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
     * Documentação de Solutions que o modelo citou pelo nome numa resposta
     * conversacional ("preciso saber como o IAM autentica…") mas que ainda
     * não estavam em `$consideredSolutions` — cada página/integração
     * encontrada vira um botão de "adicionar" no chat (mesma referência
     * `{type, id}` do chips picker "Documentos específicos", então o clique
     * reusa addChip() já existente em chips.js). Reaproveita o casamento de
     * inferSolutions() — nunca sugere um nome que o modelo não podia ter
     * visto em algum lugar real do catálogo.
     *
     * @param  Collection<int, Solution>  $consideredSolutions
     * @return list<array{type: string, id: int, label: string}>
     */
    public function suggestDocumentsFor(string $text, Collection $consideredSolutions): array
    {
        // pluck('id'), não modelKeys(): ao contrário de $mentioned (sempre
        // vindo de inferSolutions(), uma Eloquent\Collection de verdade),
        // $consideredSolutions é o parâmetro público do método — aceita
        // qualquer Collection<Solution>, não só Eloquent\Collection.
        $consideredIds = $consideredSolutions->pluck('id')->all();

        $mentioned = $this->inferSolutions($this->normalize($text))
            ->reject(fn (Solution $solution) => in_array($solution->id, $consideredIds, true))
            ->values();

        if ($mentioned->isEmpty()) {
            return [];
        }

        // Só as colunas usadas nos labels — não puxa o longText `documentation`
        // (ele entra só no WHERE), por mensagem conversacional.
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

        // collect($model->all()) antes do ->map() — mesmo cuidado do ->merge()
        // em selectDocuments(): mapear direto numa Eloquent\Collection vazia
        // não a rebaixa para Support\Collection, e o merge por chave primária
        // da Eloquent quebra contra um array puro.
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
     * Páginas documentadas das Solutions escolhidas + documentação das
     * integrações em que elas participam, ordenadas pelo que casa termos do
     * pedido (contratos, payloads, endpoints citados) e cortadas juntas ao
     * mesmo orçamento de caracteres. No empate de relevância, a doc de
     * integração vem antes da página (para um flowSpec, ela é a fonte mais
     * direta). O que ficou de fora volta em `omitted` para ser sinalizado no chat.
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

        // collect($model->all()) força uma Support\Collection "pura" antes do
        // ->map() — mapear direto numa Eloquent\Collection devolveria outra
        // Eloquent\Collection (mesmo com arrays dentro), e o ->merge() abaixo
        // usaria o merge por dicionário de chave primária da Eloquent
        // (getKey()), que quebra contra um array puro.
        $units = collect($pages->all())
            ->map(fn (DocumentationPage $page) => ['kind' => 'page', 'model' => $page, 'heading' => $page->title, 'body' => $page->documentation])
            ->merge(collect($integrations->all())->map(fn (Integration $integration) => ['kind' => 'integration', 'model' => $integration, 'heading' => $integration->name, 'body' => $integration->documentation]));

        $scored = $units
            ->map(function (array $unit) use ($terms) {
                $haystack = $this->normalize($unit['heading'] . ' ' . $unit['body']);
                $unit['score'] = collect($terms)->filter(fn (string $term) => str_contains($haystack, $term))->count();

                return $unit;
            })
            // Relevância de termos manda (score * 2). Empate no score: a doc de
            // INTEGRAÇÃO vem antes da página — para gerar um flowSpec (que é a
            // própria descrição da integração: endpoints, contratos, protocolos),
            // a documentação da integração é a fonte mais direta. É só desempate:
            // uma página claramente mais relevante ao pedido ainda ganha de uma
            // integração pouco relevante.
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

        // Reapresenta cada tipo na sua ordem natural, não na ordem do score.
        $selectedPages = $selected->where('kind', 'page')->pluck('model')
            ->sortBy(fn (DocumentationPage $page) => [$page->container_id, $page->position])->values();

        $selectedIntegrations = $selected->where('kind', 'integration')->pluck('model')
            ->sortBy(fn (Integration $integration) => $integration->name)->values();

        return [$selectedPages, $selectedIntegrations, $omitted];
    }

    /**
     * Contexto escolhido na mão via chips picker — sem scoring nem corte por
     * orçamento: o que foi selecionado entra inteiro no prompt.
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
