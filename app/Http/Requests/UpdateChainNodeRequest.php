<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesChainOwner;
use App\Http\Requests\Concerns\ValidatesChainNode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edits a single node already present in the chain (a data-viz F3 block) — its
 * kind (`ChainNodeKind`, so a block can be converted from system to
 * decision/actor/start/end and back) and its title, chosen from a registered Solution or
 * free text. The root node (index 0) never reaches here — blocked in the
 * controller before any content authorization.
 */
class UpdateChainNodeRequest extends FormRequest
{
    use AuthorizesChainOwner;

    use ValidatesChainNode;

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
