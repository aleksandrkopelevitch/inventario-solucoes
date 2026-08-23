<?php

namespace App\Support;

use App\Contracts\ChainCanvas;
use App\Enums\ChainNodeKind;
use App\Models\Solution;
use App\View\Components\Solutions\IntegrationsMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Turns any `ChainCanvas`'s stored chain into the payload the F3 canvas draws
 * from — resolved node labels, logos, comments, arrows, protocols, the saved
 * layout, and every endpoint the client will call back on.
 *
 * Lifted out of `Solutions\IntegrationsMap` when a submission's AS IS / TO BE
 * drawings became a second owner. It never knew anything Integration-specific
 * except where to get its URLs, and that now comes from the owner
 * (`ChainCanvas::chainUrls()`), so one builder serves both and the canvas
 * cannot tell them apart.
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
                ->map(fn ($node, $i) => IntegrationsMap::resolveNode($node, $solutions, $comments[$i] ?? null, $mediaById))
                ->values()
                ->all(),
            'edges' => collect($chain['edges'] ?? [])
                ->map(fn ($edge) => [
                    'from'     => $edge['from'] ?? 0,
                    'to'       => $edge['to'] ?? 0,
                    'arrow'    => $edge['arrow'] ?? '->',
                    'protocol' => IntegrationsMap::resolveProtocol($edge['protocol'] ?? null),
                ])
                ->values()
                ->all(),
            'layout'   => $owner->vizLayout(),
            'editable' => Gate::allows('update', $owner),
            ...$owner->chainUrls(),
        ];
    }
}
