<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Atualiza um (ou mais) dos 8 atributos de Solução isoladamente — editados no
 * lugar a partir do próprio card do cabeçalho de detalhe (`Solutions\DetailHeader`),
 * sem abrir o painel de edição completo. Ao contrário de `UpdateSolutionRequest`
 * (usado pelo form inteiro, onde `category`/`status` são sempre obrigatórios),
 * aqui cada campo é `sometimes` — o card manda só o atributo que o usuário
 * acabou de trocar.
 */
class UpdateSolutionAttributesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('solution')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // NOT NULL no schema — nunca podem ser esvaziados por aqui.
            'category'        => ['sometimes', 'required', Rule::exists('attribute_options', 'value')->where('group', 'category')],
            'status'          => ['sometimes', 'required', Rule::exists('attribute_options', 'value')->where('group', 'status')],
            'contract_status' => ['sometimes', 'required', Rule::exists('attribute_options', 'value')->where('group', 'contract_status')],
            'support_type'    => ['sometimes', 'required', Rule::exists('attribute_options', 'value')->where('group', 'support_type')],
            // Nullable no schema — o card mostra "Não informado" e aceita limpar de volta pra null.
            'criticality' => ['sometimes', 'nullable', Rule::exists('attribute_options', 'value')->where('group', 'criticality')],
            'environment' => ['sometimes', 'nullable', Rule::exists('attribute_options', 'value')->where('group', 'environment')],
            'cloud'       => ['sometimes', 'nullable', Rule::exists('attribute_options', 'value')->where('group', 'cloud')],
            'directorate' => ['sometimes', 'nullable', Rule::exists('attribute_options', 'value')->where('group', 'directorate')],
        ];
    }

    /** Esvazia o sentinel "" do select para null, nos campos que aceitam. */
    protected function prepareForValidation(): void
    {
        foreach (['criticality', 'environment', 'cloud', 'directorate'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filled($this->input($field)) ? (string) $this->input($field) : null]);
            }
        }
    }
}
