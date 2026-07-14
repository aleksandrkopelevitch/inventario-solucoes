<?php

namespace App\Http\Controllers;

use App\Actions\SyncIntegrationFromChain;
use App\Enums\Direction;
use App\Http\Requests\AddIntegrationChainEdgeRequest;
use App\Http\Requests\AddIntegrationChainNodeRequest;
use App\Http\Requests\RemoveIntegrationChainEdgeRequest;
use App\Http\Requests\RetargetIntegrationChainEdgeRequest;
use App\Http\Requests\SaveIntegrationLayoutRequest;
use App\Http\Requests\StoreIntegrationRequest;
use App\Http\Requests\UpdateIntegrationChainNodeRequest;
use App\Http\Requests\UpdateIntegrationChainProtocolRequest;
use App\Http\Requests\UpdateIntegrationMetaRequest;
use App\Models\Integration;
use App\Models\Solution;
use App\Support\ChainLabeler;
use App\View\Components\Solutions\IntegrationsMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Integrações do detalhe de uma solução (F3). O data-viz F3 (`integration-viz.js`)
 * é quem autora a topologia (chain: nodes/edges, via `updateNode()`/`updateProtocol()`/
 * `addNode()`/`retargetEdge()` abaixo) e o layout visual (`saveLayout()`) — este
 * controller só cobre o que o data-viz ainda não sabe fazer sozinho: criar uma
 * Integration nova do zero (`store()`) e renomear/mudar status de uma já
 * existente (`update()`), nenhum dos dois mexendo na chain. `SyncIntegrationFromChain`
 * segue sendo o único lugar que deriva participants/source/target/direction a
 * partir da chain.
 */
class SolutionIntegrationController extends Controller
{
    public function __construct(
        private readonly SyncIntegrationFromChain $sync,
        private readonly ChainLabeler $labeler,
    ) {}

    /**
     * Cria uma Integration nova com a solução do contexto como nó raiz —
     * chain = {nodes: [raiz], edges: []}, pronta para o data-viz acrescentar
     * blocos (`addNode()`) e religar (`retargetEdge()`) livremente. Nome
     * opcional (cai para o nome da solução raiz); status inicial "planned",
     * ajustável depois via `update()`.
     */
    public function store(StoreIntegrationRequest $request, Solution $solution): JsonResponse
    {
        $data = $request->validated();
        $chain = [
            'nodes' => [['solution_id' => $solution->id, 'label' => null]],
            'edges' => [],
        ];

        $name = trim($data['name'] ?? '') ?: $solution->name;

        $integration = Integration::create([
            'name'        => $name,
            'slug'        => $this->uniqueSlug($name),
            'status'      => 'planned',
            'criticality' => 'medium',
            'direction'   => Direction::Unidirectional->value, // re-derivada da cadeia logo abaixo
            'chain'       => $chain,
        ]);

        $this->sync->handle($integration);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Integração criada.',
            'updatableSlots' => [IntegrationsMap::slot($solution)],
            // Seleciona a integração recém-criada na lista, abrindo o
            // data-viz já pronta para receber o primeiro bloco.
            'js' => 'document.querySelector(\'[data-ak-integration-select="' . $integration->slug . '"]\')?.click()',
        ]);
    }

    /** Renomeia / muda o status de uma integração já existente — não mexe na chain. */
    public function update(UpdateIntegrationMetaRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $integration->update($request->validated());

        return response()->json([
            'type'           => 'success',
            'message'        => 'Integração atualizada.',
            'updatableSlots' => [IntegrationsMap::slot($solution)],
        ]);
    }

    /**
     * Salva o layout visual do grafo F3 (posições dos blocos, âncoras das
     * pontas e comentário em markdown de cada bloco, todos por índice do nó
     * na chain). Só apresentação — a `chain` continua a fonte da topologia,
     * então NÃO chamamos SyncIntegrationFromChain aqui nem tocamos
     * participants/source/target/direction.
     */
    public function saveLayout(SaveIntegrationLayoutRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $integration->update([
            'viz_layout' => $request->safe()->only(['nodes', 'edges', 'comments']),
        ]);

        return response()->json([
            'type'    => 'success',
            'message' => 'Layout salvo.',
        ]);
    }

    /**
     * Atualiza o título de um único nó (bloco do data-viz F3) — escolhido de
     * uma Solução cadastrada (puxa nome/logo/atributos) ou texto livre.
     * Continua editando a `chain` (fonte de verdade da topologia), então
     * SyncIntegrationFromChain roda de novo: trocar a Solução de um nó pode
     * mudar participants/source/target/direction. O nó raiz (índice 0) é
     * fixo — nunca chega aqui (bloqueado no cliente, reforçado no 404 abaixo).
     */
    public function updateNode(UpdateIntegrationChainNodeRequest $request, Solution $solution, Integration $integration, int $node): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain || $node <= 0 || ! isset($chain['nodes'][$node]), 404);

        $chain['nodes'][$node] = $request->validated();
        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));
        $comment = $integration->viz_layout['comments'][$node] ?? null;

        return response()->json([
            'type'    => 'success',
            'message' => 'Título do nó atualizado.',
            'node'    => IntegrationsMap::resolveNode($integration->chain['nodes'][$node], $solutions, $comment),
            'summary' => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Atualiza o protocolo e/ou o sentido (`arrow`) de uma única ligação
     * (aresta) — editado no lugar a partir do editor ancorado à pill de
     * protocolo no data-viz F3. Ao contrário do nó, não há ligação protegida
     * (nenhum "raiz" entre arestas); `$edge` é o índice em `chain.edges`.
     * Continua editando a `chain`, então SyncIntegrationFromChain roda de
     * novo: o escalar `integrations.protocol` (1ª ligação com protocolo, só
     * resumo) e o `direction` (bidirecional depende do `arrow`) são
     * derivados daqui também.
     */
    public function updateProtocol(UpdateIntegrationChainProtocolRequest $request, Solution $solution, Integration $integration, int $edge): JsonResponse
    {
        $chain = $integration->chain;
        $edges = $chain['edges'] ?? [];
        abort_if(! $chain || $edge < 0 || ! isset($edges[$edge]), 404);

        $data = $request->validated();
        $edges[$edge]['protocol'] = $data['protocol'];
        if (array_key_exists('arrow', $data)) {
            $edges[$edge]['arrow'] = $data['arrow'];
        }
        $chain['edges'] = $edges;

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Ligação atualizada.',
            'protocol' => IntegrationsMap::resolveProtocol($edges[$edge]['protocol']),
            'arrow'    => $edges[$edge]['arrow'] ?? '->',
        ]);
    }

    /**
     * Acrescenta um novo bloco ao final da cadeia (painel "Adicionar bloco"
     * do data-viz F3) — escolhido de uma Solução cadastrada ou texto livre.
     * Quando o painel informa uma seta (`data['arrow']` presente), liga o
     * bloco novo ao nó atualmente no final por uma ligação nova (seta/
     * protocolo do painel); quando não informa ("Sem conexão"), o bloco
     * nasce isolado, sem nenhuma ligação. De qualquer forma esse é só o
     * ponto de partida: o usuário pode em seguida arrastar a ponta de
     * qualquer ligação até este bloco (`retargetEdge()`) ou usar o "modo
     * ligar" para criar uma ligação nova até ele (`addEdge()`), religando a
     * cadeia num grafo livre. Continua editando a `chain` (fonte de verdade
     * da topologia), então SyncIntegrationFromChain roda de novo.
     */
    public function addNode(AddIntegrationChainNodeRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain, 404);

        $data = $request->validated();
        $chain['nodes'][] = ['solution_id' => $data['solution_id'], 'label' => $data['label']];
        $newIndex = count($chain['nodes']) - 1;

        $edge = null;
        if ($data['arrow']) {
            $edge = [
                'from'     => max(0, $newIndex - 1),
                'to'       => $newIndex,
                'arrow'    => $data['arrow'],
                'protocol' => $data['protocol'],
            ];
            $chain['edges'][] = $edge;
        }

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'     => 'success',
            'message'  => 'Bloco adicionado.',
            'node'     => IntegrationsMap::resolveNode($integration->chain['nodes'][$newIndex], $solutions, null),
            'from'     => $edge['from'] ?? null,
            'arrow'    => $edge['arrow'] ?? null,
            'protocol' => $edge ? IntegrationsMap::resolveProtocol($edge['protocol']) : null,
            'summary'  => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Religa uma ponta de uma ligação existente (`from` ou `to`) para outro
     * bloco qualquer — arrastar o handle da seta no data-viz F3 até outro nó
     * (`integration-viz.js::retargetEdge()`). É isto que torna a cadeia um
     * grafo livre em vez de uma linha reta: uma vez religada fora da
     * sequência 0→1→2→…, `ChainLabeler::isLinear()` passa a reprovar essa
     * chain (usado só para escolher o formato do resumo textual, ver
     * `ChainLabeler::label()`). Continua editando a `chain`, então
     * SyncIntegrationFromChain roda de novo.
     */
    public function retargetEdge(RetargetIntegrationChainEdgeRequest $request, Solution $solution, Integration $integration, int $edge): JsonResponse
    {
        $chain = $integration->chain;
        $edges = $chain['edges'] ?? [];
        abort_if(! $chain || $edge < 0 || ! isset($edges[$edge]), 404);

        $data = $request->validated();
        $edges[$edge][$data['end']] = $data['node'];
        $chain['edges'] = $edges;

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'    => 'success',
            'message' => 'Ligação atualizada.',
            'from'    => $edges[$edge]['from'],
            'to'      => $edges[$edge]['to'],
            'summary' => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Cria uma ligação nova entre dois blocos já existentes na chain — "modo
     * ligar" do data-viz F3 (botão da toolbar do bloco, depois clique em
     * outro bloco). Ao contrário de `addNode()`, não acrescenta nó nenhum;
     * ao contrário de `retargetEdge()`, não move uma ligação já existente —
     * é uma ligação nova do zero, o que permite conectar qualquer par de
     * blocos já desenhados, mesmo que não fizessem parte da mesma "linha" da
     * cadeia. Continua editando a `chain`, então SyncIntegrationFromChain
     * roda de novo.
     */
    public function addEdge(AddIntegrationChainEdgeRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain, 404);

        $data = $request->validated();
        $chain['edges'][] = [
            'from'     => $data['from'],
            'to'       => $data['to'],
            'arrow'    => $data['arrow'],
            'protocol' => $data['protocol'],
        ];

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'     => 'success',
            'message'  => 'Ligação criada.',
            'from'     => $data['from'],
            'to'       => $data['to'],
            'arrow'    => $data['arrow'],
            'protocol' => IntegrationsMap::resolveProtocol($data['protocol']),
            'summary'  => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Remove uma ligação existente da chain (botão "desligar" do editor de
     * aresta no data-viz F3) — os nós continuam existindo; se essa era a
     * única ligação de um bloco, ele passa a aparecer isolado no grafo. É o
     * que torna a interligação livre de verdade: nem todo bloco precisa
     * estar conectado a outro. `viz_layout.edges` é reindexado junto (é
     * paralelo a `chain.edges` por posição), senão as âncoras salvas das
     * ligações depois desta deslizariam para a ligação errada.
     */
    public function removeEdge(RemoveIntegrationChainEdgeRequest $request, Solution $solution, Integration $integration, int $edge): JsonResponse
    {
        $chain = $integration->chain;
        $edges = $chain['edges'] ?? [];
        abort_if(! $chain || $edge < 0 || ! isset($edges[$edge]), 404);

        array_splice($edges, $edge, 1);
        $chain['edges'] = $edges;

        $vizLayout = $integration->viz_layout;
        if (isset($vizLayout['edges']) && array_key_exists($edge, $vizLayout['edges'])) {
            array_splice($vizLayout['edges'], $edge, 1);
        }

        $integration->update(['chain' => $chain, 'viz_layout' => $vizLayout]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'    => 'success',
            'message' => 'Ligação removida.',
            'summary' => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    public function destroy(Solution $solution, Integration $integration): JsonResponse
    {
        $this->authorize('delete', $integration);

        // O pivot integration_solution e (schema legado) documentation_blocks
        // têm cascadeOnDelete, então a exclusão limpa os vínculos sozinha.
        $integration->delete();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Integração removida.',
            'updatableSlots' => [IntegrationsMap::slot($solution)],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'integracao';
        $slug = $base;
        $suffix = 1;

        while (Integration::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
