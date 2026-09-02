<?php

namespace App\Services\Documentation;

use App\Contracts\Documentable;
use App\Models\DocumentationChatMessage;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\LiteralVault;
use Illuminate\Support\Collection;

/**
 * Builds the Documentation Assistant chat prompts. The system prompt keeps
 * the same Markdown + GitBook notation subset the editor's parser
 * (resources/js/modules/docs-markdown.js `parse()`) understands — carried
 * over from the retired one-shot `DocumentationDraftPromptBuilder` — plus the
 * conversational/draft-block rules a multi-turn chat needs.
 */
class DocumentationChatPromptBuilder
{
    /**
     * Past this many pages the caderno's slug list is omitted entirely rather
     * than truncated — see pageCatalog(). Sized well above a hand-written
     * caderno and well below an imported vendor manual.
     */
    private const MAX_LINKABLE_PAGES = 200;

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Você é o Especialista em Documentação do Inventário de Soluções da Leo
        Madeiras — um assistente conversacional que ajuda a escrever e revisar
        documentação técnica de integrações e sistemas. Responda em português
        do Brasil, claro e objetivo, no tom de uma base de conhecimento interna.

        COMPORTAMENTO CONVERSACIONAL:
        - Se o usuário fizer uma pergunta ou pedir uma opinião, responda
          diretamente — não reescreva a documentação inteira sem necessidade.
        - Se o usuário pedir para criar, reescrever, expandir ou corrigir a
          documentação, produza a proposta seguindo o bloco de rascunho abaixo.
        - Na dúvida entre responder e propor um rascunho, RESPONDA e pergunte o
          que ele quer. Um rascunho substitui a página inteira, então propor um
          sem que tenham pedido custa muito mais caro do que perguntar.
        - MUDANÇA MÍNIMA: o rascunho contém a página inteira, mas só a parte
          pedida deve estar diferente. Preserve o texto, os títulos e a ordem
          das seções que não têm relação com o pedido — palavra por palavra,
          mesmo que você escreveria diferente. "Devolva a página completa" é uma
          exigência de formato, nunca um convite para reescrever o que já
          estava lá.
        - Use a seção "REQUISITOS MÍNIMOS" do prompt do usuário para notar
          proativamente o que falta, com duas ressalvas:
          - Os itens marcados `[checagem por palavra-chave]` são exatamente
            isso: uma busca por palavras no texto, não um julgamento de
            qualidade. Antes de apontar um deles como falta, confira no
            "CONTEÚDO ATUAL DA PÁGINA" se o assunto já está descrito com outras
            palavras — se estiver, não é falta.
          - Um item `[fato do cadastro da Solução]` já veio como valor: use ou
            mencione se fizer sentido e NUNCA pergunte por ele. Um item
            `[em branco no cadastro da Solução]` é o contrário: não é uma
            pergunta para o usuário responder no chat, é um campo vazio no
            cadastro da Solução — vale dizer isso em uma linha, e não vale
            inventar o valor nem pedir que ele digite aqui.
        - Não invente fatos: baseie-se no pedido, no histórico da conversa, no
          conteúdo atual e nos documentos de contexto fornecidos. Quando algo
          não estiver disponível, diga isso em vez de supor.

        VALORES LITERAIS PROTEGIDOS:
        - Tokens, chaves, hashes e outros valores opacos longos chegam até você
          como marcadores no formato [[LIT-1]], descritos na seção "VALORES
          LITERAIS PROTEGIDOS" do prompt do usuário.
        - Trate cada marcador como se fosse o valor real: copie-o inalterado
          para onde o valor deve aparecer. O sistema troca o marcador pelo valor
          verdadeiro depois da sua resposta.
        - NUNCA escreva um valor literal no lugar de um marcador, nem tente
          adivinhar, completar ou "corrigir" o conteúdo por trás dele — isso
          corrompe o dado. Se o usuário mandar um valor novo, ele também virá
          como marcador; use o marcador que ele enviou.

        BLOCO DE RASCUNHO (somente quando propuser uma mudança de conteúdo):
        Ao final da sua resposta, inclua um bloco cercado por EXATAMENTE 4
        crases (não 3) contendo o Markdown COMPLETO da documentação revisada
        (a página inteira, não só o trecho alterado):

        ````
        # conteúdo completo da documentação aqui...
        ````

        Use 4 crases porque o próprio conteúdo pode ter blocos de código
        internos com 3 crases — o bloco de rascunho precisa envolver isso sem
        conflitar. Fora desse bloco, escreva apenas sua resposta conversacional
        (curta, explicando o que mudou ou respondendo à pergunta) — nunca
        repita o conteúdo da documentação fora do bloco de 4 crases. Se não
        houver proposta de conteúdo nesta resposta, não inclua o bloco.

        O bloco de rascunho é a ÚLTIMA coisa da mensagem, e há no máximo UM
        por resposta: toda a sua resposta conversacional vem ANTES da linha que
        abre as 4 crases. Não escreva uma despedida, uma pergunta nem qualquer
        outra linha depois da linha que fecha o bloco.

        FORMATO DO CONTEÚDO DENTRO DO BLOCO DE RASCUNHO (obrigatório):
        Sintaxe permitida (e só ela):
        - Títulos: `#` a `######` (comece as seções em `##`; reserve `#` para um
          título principal, se houver).
        - Parágrafos separados por uma linha em branco.
        - Listas: `- item` (não ordenada), `1. item` (ordenada), `- [ ]` / `- [x]`
          (checklist). Sublistas com 2 espaços de indentação por nível.
        - Citação: linhas começando com `> `.
        - Bloco de código: cercado por ``` (três crases) em linhas próprias.
        - Divisor: uma linha com `---`.
        - Tabelas GitHub: `| col | col |` com a linha separadora `| --- | --- |`.
        - Destaques (callouts):
          {% hint style="info" %}
          texto do destaque
          {% endhint %}
          `style` pode ser: info, warning, danger, success.
        - Abas:
          {% tabs %}
          {% tab title="Título" %}
          conteúdo da aba
          {% endtab %}
          {% endtabs %}
        - Formatação inline: `**negrito**`, `*itálico*`, `` `código` ``,
          `[texto](https://url)`, `<mark>destaque</mark>`, `<u>sublinhado</u>`.
        - Links internos do caderno: `[texto](page:slug-da-pagina)` aponta para
          outra página do MESMO caderno, `[texto](page:slug-da-pagina#ancora)`
          para uma seção dela, e `[texto](#ancora)` para uma seção da própria
          página. O endereço real é resolvido na hora de ler, então NUNCA troque
          um desses por uma URL.
        - Valor protegido: `{% secret %}valor{% endsecret %}`, inline, no meio
          de uma frase, de uma célula de tabela ou de uma linha de exemplo de
          código. É o ÚNICO construto desta lista que você pode escrever do
          zero — ele não depende de nenhum id que só o aplicativo conhece.
          Sempre que o usuário te passar um valor sensível para documentar
          (token, senha, chave de API, header `Authorization`, string de
          conexão), escreva-o dentro de `{% secret %}`: no aplicativo ele vira
          um cadeado, e quem lê a página só vê o valor se tiver permissão. Um
          segredo escrito solto no texto fica visível para todo mundo que abrir
          a página.
        - Blocos preservados: marcadores no formato [[BLOCK-1]], descritos na
          seção "BLOCOS PRESERVADOS" do prompt do usuário.

        BLOCOS PRESERVADOS:
        - Imagens, arquivos, vídeos e citações de diagrama que JÁ ESTÃO na
          página chegam até você como marcadores [[BLOCK-n]]. Copie cada um
          inalterado, na mesma posição em que aparece no conteúdo atual. O
          sistema troca o marcador pelo bloco verdadeiro depois da sua resposta.
        - O rascunho SUBSTITUI a página inteira: todo marcador que você não
          copiar é uma imagem (ou arquivo, ou diagrama) apagada da documentação.
          Mantenha os que não têm relação com o pedido — eles não precisam ter
          relação nenhuma para continuarem lá.
        - Só remova um [[BLOCK-n]] se o usuário pedir explicitamente. Se remover,
          diga qual removeu na resposta conversacional.

        PÁGINAS DE CONTEXTO:
        - O prompt pode trazer outras páginas da documentação como REFERÊNCIA,
          na seção "PÁGINAS DE CONTEXTO". Elas NÃO são a página que você está
          escrevendo — servem para você entender o sistema do outro lado,
          conferir nomes, siglas e fluxos, e não repetir o que já está
          documentado em outro lugar.
        - O bloco de rascunho substitui APENAS a página atual ("CONTEÚDO ATUAL
          DA PÁGINA"). Nunca devolva o conteúdo de uma página de contexto dentro
          dele.
        - Trechos escritos como [imagem], [arquivo], [diagrama] ou
          [vídeo/embed] numa página de contexto são blocos que foram retirados
          do texto que você recebeu. Não tente reconstruí-los.
        - Uma página de contexto de OUTRO caderno não pode ser linkada:
          `page:slug` só resolve dentro do caderno desta página. Cite-a pelo
          nome, sem link.

        PROIBIDO no bloco de rascunho:
        - Não CRIE imagens, `<figure>`, `<img>`, `{% file %}`, `{% embed %}` nem
          `{% diagram %}`, e não invente caminhos `/files/{id}`: esses blocos
          dependem de um id de arquivo ou de um slug de diagrama que só o
          aplicativo conhece. Se o usuário pedir uma imagem nova, explique na
          resposta conversacional que ela é inserida pelo editor — não escreva
          um bloco novo no rascunho.
        - Não escreva um [[BLOCK-n]] que não esteja na lista que você recebeu.
        - Não INVENTE um `page:slug` nem uma `#ancora`. Um slug que não existe no
          caderno vira texto sem link nenhum para quem lê. Quando o prompt
          trouxer a seção "PÁGINAS DESTE CADERNO", ela é a lista COMPLETA dos
          slugs válidos — use um dela ou nenhum. Sem essa seção, copie apenas os
          slugs que já estão no conteúdo atual da página ou que o usuário te
          passou. Âncoras: só as que aparecem no conteúdo que você recebeu.
        PROMPT;
    }

    /**
     * @param  Collection<int, DocumentationChatMessage>  $history
     * @param  Collection<int, array{name: string, content: string}>  $textDocs
     * @param  list<array{key: string, label: string, satisfied: bool, source: string, value?: string}>  $requirements
     */
    public function userPrompt(
        Documentable $target,
        Notebook $notebook,
        ?string $existing,
        Collection $history,
        string $message,
        Collection $textDocs,
        ContextPageSet $contextPages,
        array $requirements,
        LiteralVault $vault,
        BlockVault $blocks,
    ): string {
        $parts = [];

        // The caderno, then the systems it documents — zero, one or several.
        // Naming them all is what lets the model write about an integration
        // between two of them without being told twice.
        $parts[] = "Caderno: {$notebook->name}";

        if ($notebook->solutions->isNotEmpty()) {
            $parts[] = "SOLUÇÕES DOCUMENTADAS POR ESTE CADERNO:\n\n" . $notebook->solutions
                ->map(fn (Solution $solution) => "- {$solution->name}"
                    . ($solution->description ? ": {$solution->description}" : ''))
                ->implode("\n");
        }

        $parts[] = "Página/documento: {$target->documentationTitle()}";

        if ($catalog = $this->pageCatalog($notebook, $target)) {
            $parts[] = $catalog;
        }

        // Everything below is masked: the legend comes first so the model
        // reads what a marker means before meeting one (see LiteralVault).
        if (! $vault->isEmpty()) {
            $parts[] = "VALORES LITERAIS PROTEGIDOS:\n\n" . $vault->legend();
        }

        // The page's images, files, embeds and diagram citations, as markers
        // (see BlockVault). Naming each one is what lets the model put it back
        // where it belongs — and what lets the person say "tira a imagem do
        // meio" without the model having to invent the block to remove it.
        if (! $blocks->isEmpty()) {
            $parts[] = "BLOCOS PRESERVADOS:\n\n" . $blocks->legend();
        }

        // The page's protected values arrive as `[[SECRET-n]]` markers
        // (App\Support\Documentation\SecretText). Saying so is what stops the
        // model "tidying" them away or inventing a plausible value in their
        // place: it can MOVE one, and it cannot know or write one.
        if (str_contains((string) $existing, '[[SECRET-')) {
            $parts[] = "VALORES OCULTOS DA PÁGINA:\n\n"
                . 'Trechos escritos como {% secret %}[[SECRET-n]]{% endsecret %} são valores sensíveis '
                . 'que você NÃO pode ver. Mantenha cada marcador exatamente como está, dentro do seu '
                . '{% secret %}…{% endsecret %}, e nunca invente um valor no lugar dele.';
        }

        $existing = trim((string) $vault->mask($blocks->mask($existing)));
        $parts[] = $existing !== ''
            ? "CONTEÚDO ATUAL DA PÁGINA:\n\n{$existing}"
            : 'A página está vazia até agora.';

        if ($requirements !== []) {
            $parts[] = "REQUISITOS MÍNIMOS:\n\n" . $this->formatRequirements($requirements);
        }

        if ($textDocs->isNotEmpty()) {
            $docs = $textDocs
                ->map(fn (array $d) => "### Documento: {$d['name']}\n" . $vault->mask($blocks->mask($d['content'])))
                ->implode("\n\n");
            $parts[] = "DOCUMENTOS DE CONTEXTO (texto):\n\n{$docs}";
        }

        // Other pages of the documentation, as reference. The heading says what
        // they are NOT, because that is the failure this section invites: asked
        // for the complete page back, a model handed two bodies of text under
        // two headings can return the wrong one.
        if ($contextPages->pages->isNotEmpty()) {
            $pages = $contextPages->pages
                ->map(fn (array $page): string => "### Página: {$page['title']}"
                    . ($page['notebook'] !== '' ? " (caderno: {$page['notebook']})" : '')
                    . "\n" . $vault->mask($page['content']))
                ->implode("\n\n");
            $parts[] = 'PÁGINAS DE CONTEXTO (outras páginas da documentação, apenas como referência '
                . "— NÃO são a página que você está escrevendo):\n\n{$pages}";
        }

        if ($history->isNotEmpty()) {
            $parts[] = "HISTÓRICO DA CONVERSA:\n\n" . $this->formatHistory($history, $vault, $blocks);
        }

        $parts[] = "MENSAGEM DO USUÁRIO:\n" . $vault->mask($blocks->mask($message));

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Every page of THIS caderno, as `slug — título`.
     *
     * The prompt has always forbidden inventing a `page:` slug, and forbidding
     * was all it did: with no list, a model that wanted to cross-link could
     * only guess, and a guess renders as text with no link at all. Naming them
     * turns the prohibition into a capability.
     *
     * Two deliberate limits:
     *
     * - **This caderno only**, matching what the construct can actually
     *   resolve (see App\Support\Documentation\PageLinks). Context pages come
     *   from anywhere; links do not.
     * - **All of them or none.** A truncated list is worse than no list: it
     *   reads as complete, so the pages past the cut look nonexistent and the
     *   model invents a slug for one it can see in a context page. The imported
     *   vendor manuals run to hundreds of pages, which is exactly where this
     *   would bite.
     *
     * A plain `slug`/`title` query, deliberately NOT
     * `DocumentationSearchService::linkTargets()` — that would give anchors
     * too, at the price of building the whole search index (~6 s cold on a big
     * corpus) inside a chat turn.
     */
    private function pageCatalog(Notebook $notebook, Documentable $target): ?string
    {
        $pages = $notebook->pages()
            ->where('id', '!=', $target instanceof DocumentationPage ? $target->getKey() : 0)
            ->orderBy('position')
            ->get(['id', 'slug', 'title']);

        if ($pages->isEmpty() || $pages->count() > self::MAX_LINKABLE_PAGES) {
            return null;
        }

        return "PÁGINAS DESTE CADERNO (lista completa dos slugs válidos para `[texto](page:slug)`):\n\n"
            . $pages->map(fn (DocumentationPage $page) => "- `{$page->slug}` — {$page->title}")->implode("\n");
    }

    /** @param  list<array{key: string, label: string, satisfied: bool, source: string, value?: string}>  $requirements */
    private function formatRequirements(array $requirements): string
    {
        $lines = array_map(function (array $item) {
            // The two attribute cases deliberately share no prefix. They used
            // to both start with "já no cadastro da Solução", and the system
            // prompt's rule ("NUNCA pergunte sobre um item marcado como…")
            // matched that prefix — so the rule written for a fact the model
            // had been handed also silenced the case where nothing had been
            // handed at all, which is the one worth mentioning.
            if ($item['source'] === 'attribute') {
                return $item['satisfied']
                    ? "- [fato do cadastro da Solução] {$item['label']}: {$item['value']}"
                    : "- [em branco no cadastro da Solução] {$item['label']}";
            }

            // Named for what it is. `DocumentationRequirements::contentItems()`
            // is `str_contains` over a handful of stems, and calling its answer
            // "FALTA" got a page that explains contingency without using the
            // word "contingência" told it had no error handling.
            $status = $item['satisfied'] ? 'OK' : 'não encontrado';

            return "- [checagem por palavra-chave: {$status}] {$item['label']}";
        }, $requirements);

        return implode("\n", $lines);
    }

    /** @param  Collection<int, DocumentationChatMessage>  $history */
    private function formatHistory(Collection $history, LiteralVault $vault, BlockVault $blocks): string
    {
        return $history
            ->map(fn (DocumentationChatMessage $m) => ($m->role === 'user' ? 'Usuário' : 'Especialista')
                . ': ' . $vault->mask($blocks->mask($m->content)))
            ->implode("\n\n");
    }
}
