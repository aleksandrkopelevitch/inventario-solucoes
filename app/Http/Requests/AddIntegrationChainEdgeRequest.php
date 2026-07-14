<?php

namespace App\Http\Requests;

use App\Enums\Protocol;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Cria uma ligação nova entre dois blocos já existentes na chain — o "modo
 * ligar" do data-viz F3 (clique num bloco, depois em outro): diferente de
 * `AddIntegrationChainNodeRequest` (que sempre acrescenta um nó novo) e de
 * `RetargetIntegrationChainEdgeRequest` (que move a ponta de uma ligação já
 * existente), este endpoint acrescenta uma aresta nova sem tocar nos nós —
 * é o que torna a chain um grafo livre de verdade, permitindo ligar qualquer
 * par de blocos já desenhados.
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

    /** Esvazia o sentinel "" do select ("Protocolo…") para null. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'protocol' => filled($this->input('protocol')) ? (string) $this->input('protocol') : null,
        ]);
    }
}
