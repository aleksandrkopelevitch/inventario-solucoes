<?php

namespace App\Http\Controllers;

use App\Enums\ChainNodeKind;
use App\Enums\Direction;
use App\Http\Controllers\Concerns\EditsChain;
use App\Http\Controllers\Concerns\NavigatesSolutionDocs;
use App\Http\Requests\AddChainEdgeRequest;
use App\Http\Requests\AddChainImageRequest;
use App\Http\Requests\AddChainNodeRequest;
use App\Http\Requests\RemoveChainEdgeRequest;
use App\Http\Requests\RemoveChainNodeRequest;
use App\Http\Requests\RetargetChainEdgeRequest;
use App\Http\Requests\SaveChainLayoutRequest;
use App\Http\Requests\StoreIntegrationRequest;
use App\Http\Requests\UpdateChainNodeRequest;
use App\Http\Requests\UpdateChainProtocolRequest;
use App\Http\Requests\UpdateIntegrationMetaRequest;
use App\Models\Integration;
use App\Models\Solution;
use App\View\Components\Documentation\PagesNav;
use App\View\Components\Solutions\IntegrationMeta;
use App\View\Components\Solutions\IntegrationsMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Integrations for a solution — mutation endpoints for the F3 chain data-viz,
 * mounted on the integration's own unified page (`Solutions\IntegrationWorkspace`,
 * the Diagrama tab), not the solution's detail page. The F3 data-viz
 * (`integration-viz.js`) is what authors the topology (chain: nodes/edges,
 * via `updateNode()`/`updateProtocol()`/`addNode()`/`retargetEdge()` below)
 * and the visual layout (`saveLayout()`) — this controller only covers what
 * the data-viz doesn't do: creating a brand-new Integration (`store()`, which
 * then redirects into that page) and renaming/changing the status of an
 * existing one (`update()`, driven by the page's top bar —
 * `Solutions\IntegrationMeta` —, one field at a time), neither of which
 * touches the chain. `SyncIntegrationFromChain`
 * remains the only place that derives participants/source/target/direction
 * from the chain.
 */
class SolutionIntegrationController extends Controller
{
    // Only for `update()`'s response: renaming an integration also renames it
    // in the pages rail rendered beside the editor's top bar, and that rail is
    // built from the same two helpers the documentation controllers use.
    use EditsChain, NavigatesSolutionDocs;

    /**
     * Creates a brand-new Integration with the context solution as the root
     * node — chain = {nodes: [root], edges: []}, ready for the data-viz to
     * freely add blocks (`addNode()`) and wire them (`addEdge()`/`retargetEdge()`).
     * Name is optional (falls back to the root solution's name); initial status
     * is "planned", adjustable afterwards via `update()`.
     */
    public function store(StoreIntegrationRequest $request, Solution $solution): JsonResponse
    {
        $data = $request->validated();
        $chain = [
            'nodes' => [['solution_id' => $solution->id, 'label' => null, 'kind' => ChainNodeKind::System->value]],
            'edges' => [],
        ];

        $name = trim($data['name'] ?? '') ?: $solution->name;

        $integration = Integration::create([
            'name'        => $name,
            'slug'        => $this->uniqueSlug($name),
            'status'      => 'planned',
            'criticality' => 'medium',
            'direction'   => Direction::Unidirectional->value, // re-derived from the chain right below
            'chain'       => $chain,
        ]);

        $integration->afterChainMutation();

        return response()->json([
            'type'    => 'success',
            'message' => 'Integração criada.',
            // Straight into the new integration's own page — an integration
            // created from the solution's list is empty by definition (one
            // root node, no documentation), so the list it was created from
            // has nothing left to show about it. Refreshing that list instead
            // would leave the user one extra click from the only screen where
            // the next step happens.
            'redirect' => route('solutions.integrations.docs.edit', [$solution, $integration]),
        ]);
    }

    /**
     * Renames / changes the status of an existing integration — doesn't touch
     * the chain. Called one field at a time by the editor's top bar
     * (`Solutions\IntegrationMeta`), so the response refreshes both places
     * that name the integration on that screen: the top bar itself and the
     * pages rail beside it.
     */
    public function update(UpdateIntegrationMetaRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $integration->update($request->validated());

        return response()->json([
            'type'           => 'success',
            'message'        => 'Integração atualizada.',
            'updatableSlots' => [
                IntegrationMeta::slot($solution, $integration),
                PagesNav::slot(
                    $this->solutionPagesNav($solution, null),
                    $this->solutionIntegrationsNav($solution, $integration),
                    route('solutions.docs.pages.store', $solution),
                    $solution->name,
                    route('solutions.show', $solution),
                ),
            ],
        ]);
    }

    /*
     |--------------------------------------------------------------------------
     | Chain mutations
     |--------------------------------------------------------------------------
     |
     | The bodies live in `Concerns\EditsChain`, which performs them against
     | any `ChainCanvas`. They moved there when a submission's AS IS / TO BE
     | drawings became a second owner of the same canvas: the chain's rules
     | (indices, reindexing on delete, which node is protected, what may
     | reference a Solution) are subtle enough that a second copy would
     | diverge, and the divergence would only show up as a diagram quietly
     | drawing the wrong thing.
     |
     | What stays here is what is Integration-specific: the route signature
     | (so the scoped `{solution}/{integration}` binding and each FormRequest's
     | own `authorize()` keep working untouched) and the solution context the
     | canvas's URLs are built against. Re-deriving participants /
     | source/target / direction after a write is NOT triggered here either —
     | it is `Integration::afterChainMutation()`, which the trait calls.
     */

    public function saveLayout(SaveChainLayoutRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->saveChainLayout(
            $integration,
            $request->safe()->only(['nodes', 'edges', 'comments', 'lanes', 'notes', 'theme']),
        );
    }

    public function updateNode(UpdateChainNodeRequest $request, Solution $solution, Integration $integration, int $node): JsonResponse
    {
        return $this->updateChainNode($integration->withSolutionContext($solution), $request->validated(), $node);
    }

    public function removeNode(RemoveChainNodeRequest $request, Solution $solution, Integration $integration, int $node): JsonResponse
    {
        return $this->removeChainNode($integration->withSolutionContext($solution), $node);
    }

    public function updateProtocol(UpdateChainProtocolRequest $request, Solution $solution, Integration $integration, int $edge): JsonResponse
    {
        return $this->updateChainProtocol($integration->withSolutionContext($solution), $request->validated(), $edge);
    }

    public function addNode(AddChainNodeRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->addChainNode($integration->withSolutionContext($solution), $request->validated());
    }

    public function addImageNode(AddChainImageRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->addChainImageNode($integration->withSolutionContext($solution), $request->file('image'));
    }

    public function retargetEdge(RetargetChainEdgeRequest $request, Solution $solution, Integration $integration, int $edge): JsonResponse
    {
        return $this->retargetChainEdge($integration->withSolutionContext($solution), $request->validated(), $edge);
    }

    public function addEdge(AddChainEdgeRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        return $this->addChainEdge($integration->withSolutionContext($solution), $request->validated());
    }

    public function removeEdge(RemoveChainEdgeRequest $request, Solution $solution, Integration $integration, int $edge): JsonResponse
    {
        return $this->removeChainEdge($integration->withSolutionContext($solution), $edge);
    }

    public function destroy(Solution $solution, Integration $integration): JsonResponse
    {
        $this->authorize('delete', $integration);

        // The integration_solution pivot and the (legacy schema)
        // documentation_blocks have cascadeOnDelete, so the deletion cleans
        // up the links on its own.
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
