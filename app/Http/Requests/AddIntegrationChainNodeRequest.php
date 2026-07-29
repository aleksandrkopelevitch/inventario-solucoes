<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesChainNode;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Appends a new block to the chain (data-viz F3) — a PURE node: its kind
 * (`ChainNodeKind`: system / decision / actor / start / end) plus a registered Solution or
 * free text, and nothing else. It never creates an edge: every block is born
 * isolated, and the wiring comes afterwards, by dragging an arrow out of any
 * block's port or via "connect mode" (both `AddIntegrationChainEdgeRequest`),
 * or by retargeting an existing edge onto it
 * (`RetargetIntegrationChainEdgeRequest`).
 */
class AddIntegrationChainNodeRequest extends FormRequest
{
    use ValidatesChainNode;

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
        return $this->chainNodeRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->chainNodeMessages();
    }
}
