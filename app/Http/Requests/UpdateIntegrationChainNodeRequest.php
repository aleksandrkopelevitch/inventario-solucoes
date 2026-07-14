<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Atualiza o título de um único nó já existente na chain (bloco do data-viz
 * F3) — escolhido de uma Solução cadastrada ou texto livre. O nó raiz
 * (índice 0) nunca chega aqui — bloqueado no controller antes de qualquer
 * autorização de conteúdo.
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

    /** Normaliza o sentinel "free" do select (texto livre) para solution_id nulo. */
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
