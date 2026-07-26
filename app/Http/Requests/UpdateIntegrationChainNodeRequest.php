<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesChainNode;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edits a single node already present in the chain (a data-viz F3 block) — its
 * kind (`ChainNodeKind`, so a block can be converted from system to
 * decision/actor and back) and its title, chosen from a registered Solution or
 * free text. The root node (index 0) never reaches here — blocked in the
 * controller before any content authorization.
 */
class UpdateIntegrationChainNodeRequest extends FormRequest
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
