<?php

namespace App\View\Components\Solutions;

use App\Enums\IntegrationStatus;
use App\Enums\Protocol;
use App\Models\Integration;
use App\Models\Solution;
use App\Support\ChainLabeler;
use App\Support\Heroicons;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Component;

/**
 * Lista das integrações em que a solução participa (detalhe da solução) —
 * renderizada à esquerda da seção F3 (nome + resumo da cadeia + status), com
 * form de criação (nome opcional) e ação de excluir; renomear/mudar status
 * de uma já existente é feito pelo lápis da topbar do data-viz à direita
 * (`integration-viz.js`), não por aqui. Selecionar uma linha alimenta a
 * visualização gráfica à direita (via `integration-select.js`). É slot
 * atualizável para refletir na hora as integrações criadas/editadas/excluídas.
 */
class IntegrationsMap extends Component
{
    use Renderable;

    public const DOM_ID = 'solution-integration-titles-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $integrations = $this->integrations();
        $labeler = new ChainLabeler;
        $solutions = $labeler->resolveSolutions($integrations->pluck('chain'));

        return view('components.solutions.integrations-map', [
            'domId'    => self::DOM_ID,
            'solution' => $this->solution,
            // Lista completa para o seletor de título de nó no data-viz (mesma
            // fonte do form de cadeia completo) — uma query por render do
            // componente, não por nó/linha.
            'solutionsList' => Solution::orderBy('name')->get(['id', 'name']),
            // Idem para o seletor de protocolo de uma aresta — enum fixo, sem
            // query, mas resolvido aqui (não hardcoded no JS) pra nunca
            // divergir de `App\Enums\Protocol`.
            'protocolsList' => collect(Protocol::cases())->map(fn (Protocol $p) => ['value' => $p->value, 'label' => $p->label()])->values(),
            // Idem para o select de status do editor de nome/status (lápis da
            // topbar do data-viz) — enum fixo, resolvido aqui pra nunca
            // divergir de `App\Enums\IntegrationStatus`.
            'statusesList' => collect(IntegrationStatus::cases())->map(fn (IntegrationStatus $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            'rows'         => $integrations->map(fn (Integration $integration) => [
                'integration' => $integration,
                'summary'     => $integration->chain ? $labeler->label($integration->chain, $solutions) : null,
                'graph'       => $this->graph($integration, $labeler, $solutions),
            ]),
        ]);
    }

    /**
     * Grafo resolvido consumido pela visualização à direita (`integration-viz.js`):
     * rótulos de nó já resolvidos (nome da solução ou texto livre), link para
     * o detalhe da solução (quando o nó referencia uma), comentário salvo
     * (`viz_layout.comments`, por índice do nó), setas por segmento
     * (`->`/`<-`/`<->`) e o protocolo de cada passo já traduzido para o
     * rótulo humano. É a mesma cadeia (fonte de verdade), só que pronta para
     * desenhar — nada de topologia nova é derivado aqui.
     *
     * Nós que referenciam uma Solução também carregam `logo` (URL pública,
     * para o avatar do bloco — sem logo, o JS desenha a inicial do nome) e
     * `environment`/`cloud` (rótulo + SVG do ícone, quando a opção tem um
     * configurado em "Gerenciar atributos") — exibidos discretamente em cima
     * do bloco. O SVG já vem renderizado do lado do servidor porque
     * `integration-viz.js` desenha os nós via DOM puro, sem componentes Blade.
     *
     * `edges[i]` traz `from`/`to` (índices em `nodes`) — não mais posições
     * consecutivas — porque o data-viz permite religar a ponta de qualquer
     * ligação pra qualquer bloco (`retargetEdge()`/`edgeRetargetUrl` abaixo),
     * tornando a chain um grafo livre, não uma linha reta.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array{nodes: array<int, array{label: string, solution: bool, solutionId: int|null, url: string|null, comment: string|null, logo: string|null, environment: array{label: string, icon: string|null}|null, cloud: array{label: string, icon: string|null}|null}>, edges: array<int, array{from: int, to: int, arrow: string, protocol: array{value: string, label: string}|null}>}|null
     */
    private function graph(Integration $integration, ChainLabeler $labeler, Collection $solutions): ?array
    {
        $chain = $integration->chain;
        if (! $chain) {
            return null;
        }

        // Comentários por nó (só existem quando o layout foi salvo com eles) —
        // indexados pela posição do nó na chain, mesma convenção posicional
        // já usada por `viz_layout.nodes`/`edges`. Sem uma chave de identidade
        // estável por nó (id/slug), reordenar a chain deixa comentários
        // "grudados" na posição errada — ver nota no PR.
        $comments = $integration->viz_layout['comments'] ?? [];

        return [
            'nodes' => collect($chain['nodes'] ?? [])
                ->map(fn ($node, $i) => self::resolveNode($node, $solutions, $comments[$i] ?? null))
                ->values()
                ->all(),
            'edges' => collect($chain['edges'] ?? [])
                ->map(fn ($edge) => [
                    'from'     => $edge['from'] ?? 0,
                    'to'       => $edge['to'] ?? 0,
                    'arrow'    => $edge['arrow'] ?? '->',
                    'protocol' => self::resolveProtocol($edge['protocol'] ?? null),
                ])
                ->values()
                ->all(),
            // Layout visual salvo (posições dos blocos + âncoras das pontas) +
            // fiação do botão salvar (só quando o usuário pode editar).
            'layout'   => $integration->viz_layout,
            'editable' => Gate::allows('update', $integration),
            'saveUrl'  => route('solutions.integrations.layout.save', [$this->solution, $integration]),
            // Status bruto (não o label) — pré-seleciona o select do editor
            // de nome/status (lápis da topbar); PATCH vai para o mesmo
            // `SolutionIntegrationController::update()` do form de criação.
            'status'        => $integration->status->value,
            'metaUpdateUrl' => route('solutions.integrations.update', [$this->solution, $integration]),
            // Placeholders de índice ("NODE_INDEX"/"EDGE_INDEX") substituídos
            // no JS (integration-viz.js) antes do PATCH/POST pontual — o nó
            // raiz (índice 0) é bloqueado no controller, nunca no cliente;
            // toda ligação (edge) é editável e religável.
            'nodeUpdateUrl' => route('solutions.integrations.chain.node.update', [$this->solution, $integration, 'NODE_INDEX']),
            // PATCH que atualiza protocolo e/ou sentido (arrow) de uma ligação já existente.
            'edgeUpdateUrl' => route('solutions.integrations.chain.protocol.update', [$this->solution, $integration, 'EDGE_INDEX']),
            // POST do painel "Adicionar bloco" — sempre acrescenta ao final da
            // cadeia (ou nasce isolado, se o painel não escolher uma seta); o
            // usuário pode religar essa (ou qualquer outra) ligação depois,
            // arrastando a ponta da seta (ver `edgeRetargetUrl`).
            'nodeAddUrl' => route('solutions.integrations.chain.node.add', [$this->solution, $integration]),
            // PATCH que religa a ponta de uma ligação pra outro bloco —
            // arrastar o handle da seta até um nó diferente do atual.
            'edgeRetargetUrl' => route('solutions.integrations.chain.edge.retarget', [$this->solution, $integration, 'EDGE_INDEX']),
            // POST que cria uma ligação nova entre dois blocos já existentes — "modo ligar".
            'edgeAddUrl' => route('solutions.integrations.chain.edge.add', [$this->solution, $integration]),
            // DELETE que remove uma ligação existente, sem remover os nós — é o que permite deixar um bloco sem interligação.
            'edgeRemoveUrl' => route('solutions.integrations.chain.edge.remove', [$this->solution, $integration, 'EDGE_INDEX']),
        ];
    }

    /**
     * Resolve o valor bruto do protocolo de uma ligação (`chain.edges[i].protocol`)
     * para o formato `{value,label}` consumido pelo data-viz — usado tanto ao montar
     * o grafo inteiro (acima) quanto pelo endpoint de edição pontual de
     * protocolo (`SolutionIntegrationController::updateProtocol()`), pelo
     * mesmo motivo de `resolveNode()`: as duas rotas nunca podem divergir no
     * formato do campo resolvido.
     */
    public static function resolveProtocol(?string $protocol): ?array
    {
        if (! filled($protocol)) {
            return null;
        }

        return ['value' => $protocol, 'label' => Protocol::tryFrom($protocol)?->label() ?? $protocol];
    }

    /**
     * Resolve um nó da chain para o formato consumido pelo data-viz. Usado
     * tanto ao montar o grafo inteiro (acima) quanto pelo endpoint de edição
     * pontual de título de um nó (`SolutionIntegrationController::updateNode()`),
     * para as duas rotas nunca divergirem no formato dos campos resolvidos.
     *
     * @param  array{solution_id?: int|null, label?: string|null}  $node
     * @param  Collection<int, Solution>  $solutions
     * @return array{label: string, solution: bool, solutionId: int|null, url: string|null, comment: string|null, logo: string|null, environment: array{label: string, icon: string|null}|null, cloud: array{label: string, icon: string|null}|null}
     */
    public static function resolveNode(array $node, Collection $solutions, ?string $comment = null): array
    {
        $solution = $solutions[$node['solution_id'] ?? null] ?? null;

        return [
            'label'       => (new ChainLabeler)->nodeLabel($node, $solutions),
            'solution'    => (bool) $solution,
            'solutionId'  => $solution?->id,
            'url'         => $solution ? route('solutions.show', $solution) : null,
            'comment'     => $comment,
            'logo'        => $solution?->logo_path ? Storage::disk('public')->url($solution->logo_path) : null,
            'environment' => self::attributeBadge($solution?->environment_label, $solution?->environment_icon),
            'cloud'       => self::attributeBadge($solution?->cloud_label, $solution?->cloud_icon),
        ];
    }

    /**
     * Rótulo + SVG (outline) de um atributo de Solução, ou null se a solução
     * não tiver esse atributo definido. Sem classe Tailwind no SVG — o
     * dimensionamento é feito pelo CSS escopado do bloco
     * (`.ak-viz-node-attr-icon svg`), já que este HTML nunca passa pelo
     * scanner do Tailwind (é montado em JS a partir do JSON do grafo).
     */
    private static function attributeBadge(?string $label, ?string $icon): ?array
    {
        if (! $label) {
            return null;
        }

        return ['label' => $label, 'icon' => Heroicons::outlineSvg($icon)];
    }

    /**
     * Integrações em que a solução participa — mesmo recorte do pivot usado
     * pela modal de gerenciamento (`SolutionIntegrationController::panel()`).
     * `unique('id')` cobre o caso de a própria solução participar duas vezes
     * da mesma integração (ida e volta), que devolveria a integração
     * duplicada pelo join do pivot.
     *
     * @return Collection<int, Integration>
     */
    private function integrations(): Collection
    {
        return $this->solution->integrations()
            ->orderBy('integrations.name')
            ->get(['integrations.id', 'integrations.name', 'integrations.slug', 'integrations.status', 'integrations.chain', 'integrations.viz_layout'])
            ->unique('id')
            ->values();
    }
}
