<?php

namespace App\Support;

use App\Contracts\ChainCanvas;
use App\Enums\ChainNodeKind;
use App\Enums\Protocol;
use App\Models\Solution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Turns any `ChainCanvas`'s stored chain into the payload the F3 canvas draws
 * from — resolved node labels, logos, comments, arrows, protocols, the saved
 * layout, and every endpoint the client will call back on.
 *
 * Lifted out of the solution-detail view component when a submission's AS IS /
 * TO BE drawings became a second owner. It never knew anything owner-specific
 * except where to get its URLs, and that now comes from the owner
 * (`ChainCanvas::chainUrls()`), so one builder serves all of them and the
 * canvas cannot tell them apart. The single-field resolvers below moved here
 * with it for the same reason: they are called both when building a whole
 * graph and by the endpoints that add or edit ONE node/edge
 * (`Concerns\EditsChain`), and the two paths must never diverge in the shape
 * of a resolved field — that is a contract with the client, which patches its
 * own DOM from it.
 *
 * No topology is derived here: it is the same chain, just ready to draw.
 */
class ChainGraph
{
    /**
     * @param  Collection<int, Solution>  $solutions  pre-resolved by ChainLabeler
     * @return array<string, mixed>|null null when nothing has been drawn yet
     */
    public static function for(ChainCanvas $owner, ChainLabeler $labeler, Collection $solutions): ?array
    {
        $chain = $owner->chainData();

        if (! $chain) {
            return null;
        }

        // Comments per node — indexed by the node's position in the chain, the
        // same positional convention `viz_layout.nodes`/`edges` already use.
        $comments = $owner->vizLayout()['comments'] ?? [];

        // One query for every image node's media instead of one per node.
        $mediaIds = collect($chain['nodes'] ?? [])
            ->filter(fn ($node) => ChainNodeKind::fromNode($node) === ChainNodeKind::Image)
            ->pluck('media_id')
            ->filter()
            ->unique()
            ->values();
        $mediaById = $mediaIds->isEmpty() ? collect() : Media::whereIn('id', $mediaIds)->get()->keyBy('id');

        return [
            'nodes' => collect($chain['nodes'] ?? [])
                ->map(fn ($node, $i) => self::resolveNode($node, $solutions, $comments[$i] ?? null, $mediaById))
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
            'layout'   => $owner->vizLayout(),
            'editable' => Gate::allows('update', $owner),
            ...$owner->chainUrls(),
        ];
    }

    /**
     * Resolves a chain node into the format the canvas consumes.
     *
     * `kind` (`ChainNodeKind`) drives the block's shape/colour — a decision
     * block is drawn as a chamfered hexagon, an actor and start/end as small
     * circles with the label written below them —, with `icon` bringing that
     * kind's heroicon already rendered (the JS builds nodes in plain DOM,
     * without Blade). Nodes stored before kinds existed have no `kind` key:
     * they read as `system`.
     *
     * `media_id` (Image nodes only) resolves to `mediaUrl` — the authenticated
     * `/files/{id}` URL (same convention as documentation-embedded images),
     * never a raw disk URL. `$mediaById`, when given, is a batch already
     * loaded by `for()` (avoids N+1 across every image node); single-node call
     * sites (`addChainNode()`, `updateChainNode()`, `addChainImageNode()`)
     * leave it null and this queries for that one node's media on its own.
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

    /**
     * Resolves the raw protocol value of a link (`chain.edges[i].protocol`)
     * into the `{value,label}` shape the canvas consumes — free text is kept
     * verbatim, a registered `Protocol` gets its label.
     *
     * @return array{value: string, label: string}|null
     */
    public static function resolveProtocol(?string $protocol): ?array
    {
        if (! filled($protocol)) {
            return null;
        }

        return ['value' => $protocol, 'label' => Protocol::tryFrom($protocol)?->label() ?? $protocol];
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
     *
     * @return array{label: string, icon: string|null}|null
     */
    private static function attributeBadge(?string $label, ?string $icon): ?array
    {
        if (! $label) {
            return null;
        }

        return ['label' => $label, 'icon' => Heroicons::outlineSvg($icon)];
    }
}
