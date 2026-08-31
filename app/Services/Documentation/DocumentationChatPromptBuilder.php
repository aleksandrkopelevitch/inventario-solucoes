<?php

namespace App\Services\Documentation;

use App\Contracts\Documentable;
use App\Models\DocumentationChatMessage;
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
        - Use a seção "REQUISITOS MÍNIMOS" fornecida no prompt do usuário para
          notar proativamente o que falta no conteúdo — mas NUNCA pergunte
          sobre um item marcado como "já no cadastro da Solução": esse valor já
          foi te entregue como fato, é só usar (ou mencionar) se fizer sentido.
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

        PROIBIDO no bloco de rascunho:
        - Não CRIE imagens, `<figure>`, `<img>`, `{% file %}`, `{% embed %}` nem
          `{% diagram %}`, e não invente caminhos `/files/{id}`: esses blocos
          dependem de um id de arquivo ou de um slug de diagrama que só o
          aplicativo conhece. Se o usuário pedir uma imagem nova, explique na
          resposta conversacional que ela é inserida pelo editor — não escreva
          um bloco novo no rascunho.
        - Não escreva um [[BLOCK-n]] que não esteja na lista que você recebeu.
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

        if ($history->isNotEmpty()) {
            $parts[] = "HISTÓRICO DA CONVERSA:\n\n" . $this->formatHistory($history, $vault, $blocks);
        }

        $parts[] = "MENSAGEM DO USUÁRIO:\n" . $vault->mask($blocks->mask($message));

        return implode("\n\n---\n\n", $parts);
    }

    /** @param  list<array{key: string, label: string, satisfied: bool, source: string, value?: string}>  $requirements */
    private function formatRequirements(array $requirements): string
    {
        $lines = array_map(function (array $item) {
            if ($item['source'] === 'attribute') {
                return $item['satisfied']
                    ? "- [já no cadastro da Solução] {$item['label']}: {$item['value']}"
                    : "- [já no cadastro da Solução, mas não preenchido] {$item['label']}";
            }

            $status = $item['satisfied'] ? 'OK' : 'FALTA';

            return "- [{$status}] {$item['label']}";
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
