<?php

namespace App\Services\Documentation;

use App\Enums\ArtifactDiagramType;
use App\Models\DocumentationPage;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\SecretText;

/**
 * Prompts for the four Archify artifact types.
 *
 * The contract is Archify's own JSON schema rather than one of ours, because
 * Archify's validator is what judges the answer — an IR of our own in front of
 * it would be a second vocabulary to keep in step with a vendored schema, and
 * the first time they drifted the error would arrive in the renderer rather
 * than in the validator.
 *
 * What we DO keep out of the model's hands is geometry that is mechanical
 * rather than semantic: a sequence's `y` is assigned by `PageArtifactService`
 * from message order. Everything the model is asked for here is a fact about
 * the flow — who, in what order, in which lane, at which stage.
 */
class PageArtifactPromptBuilder
{
    public function systemPrompt(ArtifactDiagramType $type): string
    {
        $contract = $this->contract($type);

        return <<<PROMPT
        Você lê uma página de documentação técnica da Leo Madeiras e devolve UM
        diagrama do tipo "{$type->value}" descrevendo o que a página conta.

        Responda APENAS com um objeto JSON dentro de um bloco ```json. Nenhum
        texto antes ou depois — quem lê a sua resposta é um programa.

        {$contract}

        VOCABULÁRIO DE `type` (o que cada bloco é tecnicamente):
        `frontend`, `backend`, `database`, `cloud`, `security`, `messagebus`,
        `external`. Escolha pelo papel real do sistema na página; `external`
        para qualquer coisa fora da Leo.

        REGRAS QUE VALEM PARA OS QUATRO TIPOS:
        - Desenhe SOMENTE o que a página diz. Nada de completar a arquitetura
          com o que costuma existir — nada de supor um banco, uma fila, um
          retry ou um monitoramento que ninguém escreveu.
        - Rótulos curtos. Um rótulo é uma etiqueta, não uma frase.
        - Entre 4 e 12 blocos. Se a página descreve pouco, o diagrama é pequeno;
          se descreve muito, escolha o fio principal em vez de tentar caber
          tudo.
        - Todo `id` que aparece numa ligação precisa existir entre os blocos.
        - `meta.title` é o nome do diagrama: curto e específico.
        - Escreva os rótulos em português, como a página.

        Se a página não descreve nada do tipo pedido — não há sequência de
        chamadas, ou não há pipeline de dados —, responda
        `{"error": "a página não descreve <o que falta>"}` em vez de inventar
        um diagrama. Isso é uma resposta correta, não uma falha.
        PROMPT;
    }

    public function userPrompt(DocumentationPage $page): string
    {
        // Masked and stripped for the reasons the assistant's prompt documents:
        // a protected value must never be quotable into a diagram label, and a
        // media block carries an id no model can author. Neither helps draw a
        // flow.
        $content = BlockVault::strip(SecretText::mask($page->documentation));

        return 'PÁGINA: ' . $page->title . "\n\nCONTEÚDO DA PÁGINA:\n\n" . $content;
    }

    /**
     * The repair round: the diagnostics Archify itself produced, and the spec
     * that produced them. Not the page — the failure being corrected is a shape
     * failure, and handing back the source material invites a redraw instead of
     * a fix.
     *
     * @param  list<string>  $problems
     */
    public function repairPrompt(string $rawJson, array $problems): string
    {
        $list = implode("\n", array_map(fn (string $problem) => '- ' . $problem, $problems));

        return <<<PROMPT
        O validador recusou esse JSON:

        {$list}

        Corrija APENAS esses pontos e devolva o objeto JSON completo de novo,
        no mesmo bloco ```json. Não redesenhe o diagrama e não acrescente nem
        remova nada que não esteja na lista acima.

        JSON anterior:

        ```json
        {$rawJson}
        ```
        PROMPT;
    }

    /** The per-type shape, written as the example the model copies. */
    private function contract(ArtifactDiagramType $type): string
    {
        return match ($type) {
            ArtifactDiagramType::Sequence => <<<'TXT'
            FORMATO (sequência — quem chama quem, em ordem):

            ```json
            {
              "schema_version": 1,
              "diagram_type": "sequence",
              "meta": { "title": "Consulta de pedido" },
              "participants": [
                { "id": "loja", "type": "frontend", "label": "Loja online" },
                { "id": "api", "type": "backend", "label": "API de pedidos" },
                { "id": "erp", "type": "external", "label": "SAP S/4HANA" }
              ],
              "messages": [
                { "from": "loja", "to": "api", "label": "GET /pedidos/{id}" },
                { "from": "api", "to": "erp", "label": "BAPI_SALESORDER_GETDETAIL" },
                { "from": "erp", "to": "api", "label": "dados do pedido", "variant": "return" },
                { "from": "api", "to": "loja", "label": "200 OK", "variant": "return" }
              ]
            }
            ```

            - `messages` está em ORDEM CRONOLÓGICA. Não escreva `y`: a posição
              vertical é calculada a partir dessa ordem.
            - `variant`: `"return"` para uma resposta, `"security"` para um passo
              de autenticação, `"dashed"` para algo assíncrono, `"emphasis"` para
              o passo principal. Omita para o caso normal.
            TXT,

            ArtifactDiagramType::Dataflow => <<<'TXT'
            FORMATO (fluxo de dados — de onde vem, o que transforma, onde para):

            ```json
            {
              "schema_version": 1,
              "diagram_type": "dataflow",
              "meta": { "title": "Carga diária de vendas" },
              "stages": [
                { "label": "Origem" },
                { "label": "Transformação" },
                { "label": "Consumo" }
              ],
              "nodes": [
                { "id": "erp", "type": "external", "label": "SAP S/4HANA", "stage": 0, "row": 0 },
                { "id": "job", "type": "backend", "label": "DAG de carga", "stage": 1, "row": 0 },
                { "id": "bq", "type": "database", "label": "BigQuery", "stage": 2, "row": 0 }
              ],
              "flows": [
                { "from": "erp", "to": "job", "label": "extração diária" },
                { "from": "job", "to": "bq", "label": "tabela fato" }
              ]
            }
            ```

            - `stages` são as COLUNAS, da esquerda para a direita; `stage` é o
              índice da coluna (0, 1, 2…) e `row` a linha dentro dela (0, 1, 2…).
            - Dois blocos nunca compartilham o mesmo par `stage`+`row`.
            - LIMITES DO RENDERIZADOR: no máximo 5 colunas (`stages`) e `row` de
              0 a 4. Um pipeline mais largo do que isso vira um diagrama de
              cinco etapas com o que importa em cada uma, não um sexto `stage`.
            TXT,

            ArtifactDiagramType::Lifecycle => <<<'TXT'
            FORMATO (ciclo de vida — os estados de uma execução):

            ```json
            {
              "schema_version": 1,
              "diagram_type": "lifecycle",
              "meta": { "title": "Ciclo de uma execução" },
              "lanes": [
                { "id": "main", "label": "Execução" },
                { "id": "evento", "label": "Exceções" },
                { "id": "terminal", "label": "Desfecho" }
              ],
              "states": [
                { "id": "novo", "type": "start", "label": "Recebido", "lane": "main", "col": 0 },
                { "id": "proc", "type": "active", "label": "Processando", "lane": "main", "col": 1 },
                { "id": "falha", "type": "failure", "label": "Erro", "lane": "evento", "col": 2 },
                { "id": "ok", "type": "success", "label": "Concluído", "lane": "terminal", "col": 3 }
              ],
              "transitions": [
                { "from": "novo", "to": "proc", "label": "início" },
                { "from": "proc", "to": "ok", "label": "sucesso" },
                { "from": "proc", "to": "falha", "label": "exceção" },
                { "from": "falha", "to": "proc", "label": "retentativa" }
              ]
            }
            ```

            - OS IDS DAS RAIAS SÃO RESERVADOS e não são livres: `main` é
              obrigatória e é o trilho principal (faixa de cima); `terminal` é a
              faixa de desfecho (embaixo); qualquer outro id cai na faixa do
              meio, a dos eventos. No máximo 4 raias.
            - `col` vai de 0 a 4 e é a ORDEM do estado no trilho, da esquerda
              para a direita. Dois estados ligados entre si não ficam na mesma
              coluna — a seta entre eles não teria comprimento.
            - `type`: `start`, `active`, `waiting`, `decision`, `success`,
              `failure`, `neutral`, `external`.
            - Um estado do qual se volta (`failure` com uma transição de retorno
              para o estado ativo) é como se desenha um retry.
            - OMITA o rótulo de uma transição quando ele só repete o nome do
              estado de destino: entre dois estados vizinhos não sobra espaço
              para o texto, e um rótulo que não cabe é recusado pelo validador.
            TXT,

            ArtifactDiagramType::Workflow => <<<'TXT'
            FORMATO (processo — etapas, aprovações, exceções):

            ```json
            {
              "schema_version": 2,
              "diagram_type": "workflow",
              "meta": { "title": "Abertura de chamado" },
              "lanes": [
                { "id": "solicitante", "label": "Solicitante" },
                { "id": "ti", "label": "TI" },
                { "id": "excecao", "label": "Exceção", "variant": "exception" }
              ],
              "nodes": [
                { "id": "abre", "lane": "solicitante", "col": 0, "type": "frontend", "label": "Abre o chamado" },
                { "id": "tria", "lane": "ti", "col": 1, "type": "backend", "label": "Triagem" },
                { "id": "resolve", "lane": "ti", "col": 2, "type": "backend", "label": "Resolução" },
                { "id": "recusa", "lane": "excecao", "col": 2, "type": "security", "label": "Devolvido" }
              ],
              "edges": [
                { "from": "abre", "to": "tria", "label": "novo" },
                { "from": "tria", "to": "resolve", "label": "aceito", "role": "main" },
                { "from": "tria", "to": "recusa", "label": "sem informação", "role": "error" }
              ],
              "mainPath": ["abre", "tria", "resolve"]
            }
            ```

            - `schema_version` é 2 (o layout é calculado a partir de `lane` e
              `col`). `col` vai de 0 a 5 e é a ETAPA, da esquerda para a direita;
              `lane` é quem executa.
            - `mainPath` é a sequência de ids do caminho feliz.
            - `role` numa ligação: `main`, `branch`, `async`, `return`, `error`.
            TXT,
        };
    }
}
