<?php

namespace App\Services\Documentation;

use App\Contracts\Documentable;
use App\Models\Solution;
use Illuminate\Support\Collection;

/**
 * Monta os prompts do "Assiste IA". O system prompt restringe a saída ao
 * subconjunto exato de Markdown + notação GitBook que o parser do editor
 * (resources/js/modules/docs-markdown.js `parse()`) entende, para o rascunho
 * carregar em blocos sem perder nada.
 */
class DocumentationDraftPromptBuilder
{
    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Você escreve documentação técnica de integrações e sistemas para o
        Inventário de Soluções da Leo Madeiras. Responda em português do Brasil,
        claro e objetivo, no tom de uma base de conhecimento interna.

        FORMATO DA SAÍDA (obrigatório):
        Devolva SOMENTE o corpo da documentação em Markdown com a notação
        estendida abaixo. Não escreva nenhuma introdução, comentário ou
        despedida fora do documento, e NÃO envolva a resposta inteira numa
        cerca de código.

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

        PROIBIDO:
        - Não use imagens, `<figure>`, `<img>`, `{% file %}` nem `{% embed %}`.
        - Não invente caminhos `/files/{id}` nem links para arquivos.
        - Não invente fatos: baseie-se no pedido, no conteúdo atual da página e
          nos documentos de contexto fornecidos. Quando algo não estiver
          disponível, deixe claro em vez de supor.
        PROMPT;
    }

    /**
     * @param  Collection<int, array{name: string, content: string}>  $textDocs
     */
    public function userPrompt(
        Documentable $target,
        Solution $solution,
        ?string $existing,
        string $prompt,
        Collection $textDocs,
    ): string {
        $parts = [];

        $parts[] = "Solução: {$solution->name}"
            . ($solution->description ? "\nDescrição da solução: {$solution->description}" : '');
        $parts[] = "Página/documento a escrever: {$target->documentationTitle()}";

        $existing = trim((string) $existing);
        if ($existing !== '') {
            $parts[] = "CONTEÚDO ATUAL DA PÁGINA (melhore/expanda/reescreva conforme o pedido, preservando o que fizer sentido):\n\n{$existing}";
        } else {
            $parts[] = 'A página está vazia — crie a documentação do zero.';
        }

        if ($textDocs->isNotEmpty()) {
            $docs = $textDocs
                ->map(fn (array $d) => "### Documento: {$d['name']}\n{$d['content']}")
                ->implode("\n\n");
            $parts[] = "DOCUMENTOS DE CONTEXTO (texto):\n\n{$docs}";
        }

        $parts[] = "PEDIDO DO USUÁRIO:\n{$prompt}";

        $parts[] = 'Escreva agora a documentação seguindo estritamente o formato definido.';

        return implode("\n\n---\n\n", $parts);
    }
}
