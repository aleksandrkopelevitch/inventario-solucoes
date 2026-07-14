<?php

namespace App\Http\Requests;

use App\Enums\Protocol;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Atualiza o protocolo e/ou o sentido (`arrow`) de um único passo (segmento/
 * aresta) já existente na chain — editado no lugar a partir do editor
 * ancorado à pill de protocolo no data-viz F3, sem reenviar a cadeia inteira.
 * Ao contrário do nó raiz, não há segmento protegido: toda aresta pode ter
 * seu protocolo (nullable) e seu sentido editados livremente. `arrow` é
 * `sometimes` — o painel sempre o envia junto do protocolo, mas mantém
 * compatível uma chamada que só queira atualizar o protocolo.
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
            'protocol' => ['nullable', new Enum(Protocol::class)],
            'arrow'    => ['sometimes', Rule::in(['->', '<-', '<->'])],
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
