<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removes an existing edge from the chain (the "disconnect" button in the
 * edge editor in data-viz F3) — no body, just the index into `chain.edges`
 * in the route. This is what allows a block to be left with no connection:
 * removing the only edge that kept it connected to the rest of the graph
 * does not remove the node itself.
 */
class RemoveIntegrationChainEdgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $integration = $this->route('integration');

        return $integration instanceof Integration
            && ($this->user()?->can('update', $integration) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
