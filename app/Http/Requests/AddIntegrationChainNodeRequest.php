<?php

namespace App\Http\Requests;

use App\Enums\Protocol;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Appends a new block to the end of the chain (data-viz F3) — chosen from a
 * registered Solution or free text. `arrow` is optional: when absent ("No
 * connection" in the "Add block" panel), the block is born isolated, with no
 * edge at all — the user can connect it later via "connect mode"
 * (`AddIntegrationChainEdgeRequest`) or by retargeting another edge to it
 * (`RetargetIntegrationChainEdgeRequest`). When present, it connects to the
 * node currently at the end of the chain using the panel's arrow/protocol.
 */
class AddIntegrationChainNodeRequest extends FormRequest
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
            'arrow'       => ['nullable', Rule::in(['->', '<-', '<->'])],
            'protocol'    => ['nullable', new Enum(Protocol::class)],
            'solution_id' => ['nullable', 'integer', 'exists:solutions,id', 'required_without:label'],
            'label'       => ['nullable', 'string', 'max:255', 'required_without:solution_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'solution_id.required_without' => 'Escolha um sistema ou informe o texto livre.',
            'label.required_without'       => 'Escolha um sistema ou informe o texto livre.',
        ];
    }

    /** Normalizes the select's "free" sentinel (free text) to a null solution_id. */
    protected function prepareForValidation(): void
    {
        $solutionId = $this->input('solution_id');
        $solutionId = is_numeric($solutionId) ? (int) $solutionId : null;

        $this->merge([
            'solution_id' => $solutionId,
            'label'       => $solutionId ? null : (trim((string) $this->input('label', '')) ?: null),
            'arrow'       => filled($this->input('arrow')) ? (string) $this->input('arrow') : null,
            'protocol'    => filled($this->input('arrow')) && filled($this->input('protocol')) ? (string) $this->input('protocol') : null,
        ]);
    }
}
