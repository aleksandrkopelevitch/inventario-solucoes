<?php

namespace App\View\Components\Solutions;

use App\Enums\ChainNodeKind;
use App\Enums\Protocol;
use App\Models\Integration;
use App\Models\Solution;
use App\Support\ChainGraph;
use App\Support\ChainLabeler;
use App\Support\Heroicons;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Plain nav list of the integrations the solution participates in: name,
 * chain summary and status, with a creation form (optional name) and a delete
 * action. It's the left column of the solution detail page's "integrações +
 * documentação" card — `Solutions\Documentation` is the right one, and the
 * card's frame lives in `solutions/show.blade.php` since each column is its
 * own updatable slot (creating on one side must not re-render the other).
 *
 * Each row links straight to the integration's own unified page
 * (`Solutions\IntegrationWorkspace`, the Documentação/Diagrama tabs) — the
 * graphical chain editor no longer lives inline here, it's authored on that
 * page instead, and so is the integration's name/status
 * (`Solutions\IntegrationMeta`). Creating one goes straight there too, which
 * is why `SolutionIntegrationController::store()` answers with a redirect and
 * not with this slot.
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
            'rows'     => $integrations->map(fn (Integration $integration) => [
                'integration' => $integration,
                'summary'     => $integration->chain ? $labeler->label($integration->chain, $solutions) : null,
                'editUrl'     => route('solutions.integrations.docs.edit', [$this->solution, $integration]),
            ]),
        ]);
    }

    /**
     * Resolved graph consumed by the Diagrama tab's canvas (`integration-viz.js`,
     * mounted by `Solutions\IntegrationWorkspace`): already-resolved node
     * labels (solution name or free text), link to
     * the solution detail page (when the node references one), saved comment
     * (`viz_layout.comments`, by node index), arrows per segment
     * (`->`/`<-`/`<->`) and the protocol of each step already translated to the
     * human-readable label. It's the same chain (source of truth), just ready
     * to draw — no new topology is derived here.
     *
     * Nodes that reference a Solution also carry `logo` (public URL,
     * for the block's avatar — without a logo, the JS draws the name's
     * initial) and `environment`/`cloud` (label + icon SVG, when the option has
     * one configured in "Gerenciar atributos") — shown discreetly on top
     * of the block. The SVG already comes rendered server-side because
     * `integration-viz.js` draws the nodes via plain DOM, without Blade components.
     *
     * `edges[i]` carries `from`/`to` (indices into `nodes`) — no longer
     * consecutive positions — because the data-viz allows reconnecting the
     * end of any link to any block (`retargetEdge()`/`edgeRetargetUrl` below),
     * making the chain a free graph, not a straight line.
     *
     * Public because `SolutionIntegrationController::removeNode()` returns a
     * WHOLE rebuilt graph instead of a patch: removing a node shifts every node
     * index above it, so there's nothing the client could surgically patch —
     * it re-renders. Reusing this method (rather than reindexing a second time
     * in JS) is what keeps the delete response identical in shape to the
     * `data-integration-graph` the page was first drawn from.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array{nodes: array<int, array{label: string, kind: string, icon: string|null, solution: bool, solutionId: int|null, url: string|null, comment: string|null, logo: string|null, environment: array{label: string, icon: string|null}|null, cloud: array{label: string, icon: string|null}|null, mediaUrl: string|null}>, edges: array<int, array{from: int, to: int, arrow: string, protocol: array{value: string, label: string}|null}>}|null
     */
    public function graph(Integration $integration, ChainLabeler $labeler, Collection $solutions): ?array
    {
        // The build itself lives in App\Support\ChainGraph — a submission's
        // AS IS / TO BE drawings are the same canvas over a different owner,
        // and nothing in here was ever Integration-specific except where the
        // URLs come from. This wrapper stays because the page and
        // `SolutionIntegrationController::removeNode()` both call it with the
        // browsing solution in hand, which is the context the URLs need.
        return ChainGraph::for(
            $integration->withSolutionContext($this->solution),
            $labeler,
            $solutions,
        );
    }

    /**
     * Resolves the raw protocol value of a link (`chain.edges[i].protocol`)
     * into the `{value,label}` format consumed by the data-viz — used both when
     * building the entire graph (above) and by the endpoint for single-field
     * protocol editing (`SolutionIntegrationController::updateProtocol()`), for
     * the same reason as `resolveNode()`: the two routes must never diverge in
     * the resolved field's format.
     */
    public static function resolveProtocol(?string $protocol): ?array
    {
        if (! filled($protocol)) {
            return null;
        }

        return ['value' => $protocol, 'label' => Protocol::tryFrom($protocol)?->label() ?? $protocol];
    }

    /**
     * Resolves a chain node into the format consumed by the data-viz. Used
     * both when building the entire graph (above) and by the endpoints that
     * add or edit a single node (`SolutionIntegrationController::addNode()`/
     * `updateNode()`), so the routes never diverge in the format of the
     * resolved fields.
     *
     * `kind` (`ChainNodeKind`) drives the block's shape/colour in the canvas —
     * a decision block is drawn as a chamfered hexagon, an actor as a rounded
     * badge, start/end as a small solid-color circle (green/red) with the
     * label written below it —, with `icon` bringing that kind's heroicon
     * already rendered (the JS builds nodes in plain DOM, without Blade).
     * Nodes stored before kinds existed have no `kind` key: they read as `system`.
     *
     * `media_id` (Image nodes only) resolves to `mediaUrl` — the authenticated
     * `/files/{id}` URL (same convention as documentation-embedded images),
     * never a raw disk URL. `$mediaById`, when given, is a batch already
     * loaded by `graph()` (avoids N+1 across every image node); single-node
     * call sites (`addNode()`, `updateNode()`, `addImageNode()`) leave it null
     * and this queries for that one node's media on its own.
     *
     * @param  array{solution_id?: int|null, label?: string|null, kind?: string|null, media_id?: int|null}  $node
     * @param  Collection<int, Solution>  $solutions
     * @param  Collection<int, Media>|null  $mediaById
     * @return array{label: string, kind: string, icon: string|null, solution: bool, solutionId: int|null, url: string|null, comment: string|null, logo: string|null, environment: array{label: string, icon: string|null}|null, cloud: array{label: string, icon: string|null}|null, mediaUrl: string|null}
     */
    public static function resolveNode(array $node, Collection $solutions, ?string $comment = null, ?Collection $mediaById = null): array
    {
        $kind = ChainNodeKind::fromNode($node);
        $solution = $kind->referencesSolution() ? ($solutions[$node['solution_id'] ?? null] ?? null) : null;
        $media = $kind === ChainNodeKind::Image ? self::resolveMedia($node['media_id'] ?? null, $mediaById) : null;

        return [
            'label'       => (new ChainLabeler)->nodeLabel($node, $solutions),
            'kind'        => $kind->value,
            'icon'        => Heroicons::outlineSvg($kind->icon()),
            'solution'    => (bool) $solution,
            'solutionId'  => $solution?->id,
            'url'         => $solution ? route('solutions.show', $solution) : null,
            'comment'     => $comment,
            'logo'        => $solution?->logo_path ? Storage::disk('public')->url($solution->logo_path) : null,
            'environment' => self::attributeBadge($solution?->environment_label, $solution?->environment_icon),
            'cloud'       => self::attributeBadge($solution?->cloud_label, $solution?->cloud_icon),
            'mediaUrl'    => $media ? route('files.show', $media) : null,
        ];
    }

    /** @param  Collection<int, Media>|null  $mediaById */
    private static function resolveMedia(mixed $mediaId, ?Collection $mediaById): ?Media
    {
        if (! is_int($mediaId) && ! is_numeric($mediaId)) {
            return null;
        }
        $mediaId = (int) $mediaId;

        if ($mediaById?->has($mediaId)) {
            return $mediaById->get($mediaId);
        }

        return Media::find($mediaId);
    }

    /**
     * Label + SVG (outline) of a Solution attribute, or null if the solution
     * doesn't have that attribute set. No Tailwind class on the SVG — the
     * sizing is handled by the block's scoped CSS
     * (`.ak-viz-node-attr-icon svg`), since this HTML never passes through the
     * Tailwind scanner (it's built in JS from the graph's JSON).
     */
    private static function attributeBadge(?string $label, ?string $icon): ?array
    {
        if (! $label) {
            return null;
        }

        return ['label' => $label, 'icon' => Heroicons::outlineSvg($icon)];
    }

    /**
     * Integrations the solution participates in — same pivot subset used
     * by the management modal (`SolutionIntegrationController::panel()`).
     * `unique('id')` covers the case where the solution itself participates
     * twice in the same integration (round trip), which would return the
     * integration duplicated by the pivot join.
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
