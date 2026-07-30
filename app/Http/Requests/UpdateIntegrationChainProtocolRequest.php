<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updates the protocol and/or direction (`arrow`) of a single step (segment/
 * edge) already present in the chain — edited in place from the editor
 * anchored to the protocol pill in data-viz F3, without resending the whole
 * chain. Unlike the root node, there is no protected segment: every edge can
 * have its protocol (nullable) and direction freely edited. `arrow` is
 * `sometimes` — the panel always sends it together with the protocol, but
 * this keeps it compatible with a call that only wants to update the protocol.
 *
 * `protocol` accepts free text, not just `App\Enums\Protocol` values — the
 * editor's field is a text input with a datalist of the enum's values as
 * suggestions, not a closed `<select>`. A value that happens to match an enum
 * case still resolves to that case's human label (`IntegrationsMap::resolveProtocol()`);
 * anything else is displayed as typed.
 */
class UpdateIntegrationChainProtocolRequest extends FormRequest
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
        return [
            'protocol' => ['nullable', 'string', 'max:60'],
            'arrow'    => ['sometimes', Rule::in(['->', '<-', '<->'])],
        ];
    }

    /** Empties the select's "" sentinel ("Protocol…") to null. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'protocol' => filled($this->input('protocol')) ? (string) $this->input('protocol') : null,
        ]);
    }
}
