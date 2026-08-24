<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesChainOwner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removes a block from the chain (the trash in the block's contextual toolbar
 * in data-viz F3) — no body, just the index into `chain.nodes` in the route.
 *
 * Unlike `RemoveChainEdgeRequest`, which leaves the nodes alone,
 * this necessarily takes every link touching the block with it: `chain.edges`
 * references nodes BY INDEX, so a node can't be dropped while an edge still
 * points at it. The root node (index 0) is never removable — enforced in the
 * controller, before this request's authorization even matters.
 */
class RemoveChainNodeRequest extends FormRequest
{
    use AuthorizesChainOwner;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
