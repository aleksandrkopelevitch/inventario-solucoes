<?php

namespace App\Services\Documentation;

use App\Enums\DiagramModel;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Support\Diagrams\ModelSpec;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\SecretText;

/**
 * Prompts for the four diagram models.
 *
 * Every contract below asks for SEMANTICS and nothing else — who takes part,
 * in what order, in which lane. There is not one coordinate in any of them,
 * because `ModelLayout` owns geometry: a model asked for pixels produces
 * plausible ones that overlap, and asked for an order produces an order.
 *
 * The catalog of solution names is handed over whole, on the same terms as
 * `DiagramDraftPromptBuilder`: all of them or none, so a truncated list can
 * never read as complete and send the model inventing a name for a system it
 * cannot find.
 */
class DiagramModelPromptBuilder
{
    private const MAX_CATALOG = 400;

    public function systemPrompt(DiagramModel $model): string
    {
        $contract = $this->contract($model);
        $max = ModelSpec::MAX_NODES;

        return <<<PROMPT
        Você lê uma página de documentação técnica da Leo Madeiras e devolve UM
        diagrama do tipo "{$model->value}" com o que a página conta.

        Responda APENAS com um objeto JSON dentro de um bloco ```json. Nenhum
        texto antes ou depois — quem lê a sua resposta é um programa.

        {$contract}

        REGRAS QUE VALEM PARA OS QUATRO MODELOS:
        - Desenhe SOMENTE o que a página diz. Nada de completar com o que
          costuma existir — sem supor um banco, uma fila, um retry ou um
          monitoramento que ninguém escreveu.
        - Rótulos curtos, no máximo uma linha. Um rótulo é etiqueta, não frase.
        - No máximo {$max} itens. Se a página descreve muito, escolha o fio
          principal em vez de tentar caber tudo.
        - Todo `id` citado numa ligação precisa existir na lista de itens.
        - NÃO escreva posição, coordenada, largura, altura ou ordem numérica:
          a posição de cada bloco é calculada por quem desenha. A ORDEM da
          lista já diz tudo que você precisa dizer sobre sequência.
        - `solution` é o NOME EXATO de uma solução do catálogo abaixo, copiado
          caractere por caractere. Um sistema que não está no catálogo fica só
          com `label` — isso é o certo a fazer, não um erro.
        - Escreva em português, como a página.

        Se a página não descreve nada desse tipo, responda
        `{"error": "a página não descreve <o que falta>"}` em vez de inventar
        um diagrama. Isso é uma resposta correta.
        PROMPT;
    }

    public function userPrompt(DocumentationPage $page): string
    {
        // Masked and stripped for the reasons the assistant's own prompt
        // documents: a protected value must never be quotable into a block
        // label, and a media block carries an id no model can author.
        $content = BlockVault::strip(SecretText::mask($page->documentation));

        $sections = ['PÁGINA: ' . $page->title, "CONTEÚDO DA PÁGINA:\n\n" . $content];

        if ($catalog = $this->solutionCatalog()) {
            $sections[] = $catalog;
        }

        return implode("\n\n---\n\n", $sections);
    }

    /**
     * @param  list<string>  $problems
     */
    public function repairPrompt(string $rawJson, array $problems): string
    {
        $list = implode("\n", array_map(fn (string $problem) => '- ' . $problem, $problems));

        return <<<PROMPT
        O JSON que você devolveu tem problemas:

        {$list}

        Corrija APENAS esses pontos e devolva o objeto completo de novo, no
        mesmo bloco ```json. Não redesenhe o diagrama, não acrescente nem
        remova nada que não esteja na lista acima.

        JSON anterior:

        ```json
        {$rawJson}
        ```
        PROMPT;
    }

    private function contract(DiagramModel $model): string
    {
        return match ($model) {
            DiagramModel::Sequence => <<<'TXT'
            FORMATO (sequência — quem chama quem, em ordem):

            ```json
            {
              "name": "Consulta de pedido",
              "participants": [
                { "id": "loja", "label": "Loja online" },
                { "id": "api", "label": "API de pedidos", "solution": "CWS" },
                { "id": "erp", "label": "SAP S/4HANA", "solution": "SAP S/4HANA" }
              ],
              "messages": [
                { "from": "loja", "to": "api", "label": "GET /pedidos/{id}" },
                { "from": "api", "to": "erp", "label": "BAPI_SALESORDER_GETDETAIL" },
                { "from": "erp", "to": "api", "label": "dados do pedido" },
                { "from": "api", "to": "loja", "label": "200 OK" }
              ]
            }
            ```

            - `participants` é quem troca mensagens, na ordem em que devem
              aparecer da esquerda para a direita.
            - `messages` está em ORDEM CRONOLÓGICA — é a ordem da lista que vira
              a descida no tempo. Uma resposta é uma mensagem como outra
              qualquer, só que de volta.
            - `"async": true` numa mensagem a desenha tracejada (evento, fila).
            TXT,

            DiagramModel::Lifecycle => <<<'TXT'
            FORMATO (ciclo de vida — os estados de uma execução):

            ```json
            {
              "name": "Ciclo de uma execução",
              "states": [
                { "id": "novo", "label": "Recebido", "kind": "start" },
                { "id": "proc", "label": "Processando", "kind": "active" },
                { "id": "erro", "label": "Falha de conexão", "kind": "failure" },
                { "id": "ok", "label": "Concluído", "kind": "success" }
              ],
              "transitions": [
                { "from": "novo", "to": "proc", "label": "início" },
                { "from": "proc", "to": "erro", "label": "exceção" },
                { "from": "erro", "to": "proc", "label": "retentativa" },
                { "from": "proc", "to": "ok", "label": "sucesso" }
              ]
            }
            ```

            - `kind`: `start`, `active`, `decision`, `failure` ou `success`.
            - Tudo que for `failure` vai para uma linha de baixo, e uma
              transição voltando dali para um estado ativo é como se desenha um
              repique. Os demais ficam no trilho principal, na ordem da lista.
            TXT,

            DiagramModel::Dataflow => <<<'TXT'
            FORMATO (fluxo de dados — de onde vem, o que transforma, onde para):

            ```json
            {
              "name": "Carga diária de vendas",
              "lanes": [
                { "id": "origem", "label": "Origem" },
                { "id": "transf", "label": "Transformação" },
                { "id": "consumo", "label": "Consumo" }
              ],
              "nodes": [
                { "id": "erp", "label": "SAP S/4HANA", "solution": "SAP S/4HANA", "stage": "origem" },
                { "id": "dag", "label": "DAG de carga", "stage": "transf" },
                { "id": "bq", "label": "BigQuery", "solution": "Google BigQuery", "stage": "consumo" }
              ],
              "flows": [
                { "from": "erp", "to": "dag", "label": "extração diária" },
                { "from": "dag", "to": "bq", "label": "tabela fato" }
              ]
            }
            ```

            - `lanes` são as etapas, da esquerda para a direita; cada bloco diz
              em qual está por `stage`. Dentro de uma etapa, a ordem da lista é
              a ordem de cima para baixo.
            TXT,

            DiagramModel::Workflow => <<<'TXT'
            FORMATO (processo — etapas, aprovações, exceções):

            ```json
            {
              "name": "Abertura de chamado",
              "lanes": [
                { "id": "solicitante", "label": "Solicitante" },
                { "id": "ti", "label": "TI" }
              ],
              "steps": [
                { "id": "abre", "label": "Abre o chamado", "lane": "solicitante" },
                { "id": "tria", "label": "Aprovado?", "lane": "ti", "kind": "decision" },
                { "id": "resolve", "label": "Resolução", "lane": "ti" }
              ],
              "flows": [
                { "from": "abre", "to": "tria", "label": "novo" },
                { "from": "tria", "to": "resolve", "label": "aceito" }
              ]
            }
            ```

            - `lanes` é quem executa — uma raia por pessoa, área ou sistema
              responsável. Cada etapa diz a sua por `lane`.
            - Dentro de uma raia, a ordem da lista é a ordem da esquerda para a
              direita.
            - `"kind": "decision"` desenha a etapa como losango de decisão.
            TXT,
        };
    }

    private function solutionCatalog(): ?string
    {
        $solutions = Solution::query()->orderBy('name')->get(['id', 'name']);

        if ($solutions->isEmpty() || $solutions->count() > self::MAX_CATALOG) {
            return null;
        }

        return "CATÁLOGO DE SOLUÇÕES (nomes válidos para `solution`, lista completa):\n\n"
            . $solutions->map(fn (Solution $solution) => '- ' . $solution->name)->implode("\n");
    }
}
