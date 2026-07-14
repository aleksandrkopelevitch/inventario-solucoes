<?php

namespace App\Http\Requests;

use App\Enums\Protocol;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Acrescenta um novo bloco ao final da chain (data-viz F3) — escolhido de uma
 * Solução cadastrada ou texto livre. `arrow` é opcional: quando ausente
 * ("Sem conexão" no painel "Adicionar bloco"), o bloco nasce isolado, sem
 * nenhuma ligação — o usuário pode ligá-lo depois via "modo ligar"
 * (`AddIntegrationChainEdgeRequest`) ou religando outra ligação até ele
 * (`RetargetIntegrationChainEdgeRequest`). Quando presente, liga ao nó
 * atualmente no final da cadeia pela seta/protocolo do painel.
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

    /** Normaliza o sentinel "free" do select (texto livre) para solution_id nulo. */
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
