<?php

namespace App\Http\Requests;

use App\Enums\Protocol;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Creates a new edge between two blocks already present in the chain — the
 * data-viz F3 "connect mode" (click one block, then another): unlike
 * `AddIntegrationChainNodeRequest` (which always appends a new node) and
 * `RetargetIntegrationChainEdgeRequest` (which moves the end of an existing
 * edge), this endpoint adds a new edge without touching the nodes — that's
 * what makes the chain a genuinely free graph, letting any pair of already
 * drawn blocks be connected.
 */
class AddIntegrationChainEdgeRequest extends FormRequest
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
        $integration = $this->route('integration');
        $max = max(0, count($integration?->chain['nodes'] ?? []) - 1);

        return [
            'from'     => ['required', 'integer', 'min:0', 'max:' . $max],
            'to'       => ['required', 'integer', 'min:0', 'max:' . $max, 'different:from'],
            'arrow'    => ['required', Rule::in(['->', '<-', '<->'])],
            'protocol' => ['nullable', new Enum(Protocol::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.different' => 'Uma ligação não pode conectar um bloco a ele mesmo.',
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
