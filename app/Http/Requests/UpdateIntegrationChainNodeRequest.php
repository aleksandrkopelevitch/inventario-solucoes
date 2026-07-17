<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Updates the title of a single node already present in the chain (a
 * data-viz F3 block) — chosen from a registered Solution or free text. The
 * root node (index 0) never reaches here — blocked in the controller before
 * any content authorization.
 */
class UpdateIntegrationChainNodeRequest extends FormRequest
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
        ]);
    }
}
