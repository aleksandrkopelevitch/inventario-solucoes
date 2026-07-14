<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Religa uma ponta (`from` ou `to`) de uma ligação existente da chain para
 * outro nó — arrastar o handle da seta no data-viz F3 até outro bloco
 * qualquer (`integration-viz.js::retargetEdge()`). É o que permite o grafo
 * livre: a ligação deixa de estar presa ao par de nós com que foi criada.
 */
class RetargetIntegrationChainEdgeRequest extends FormRequest
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
        $nodeCount = count($integration?->chain['nodes'] ?? []);

        return [
            'end'  => ['required', Rule::in(['from', 'to'])],
            'node' => ['required', 'integer', 'min:0', 'max:' . max(0, $nodeCount - 1)],
        ];
    }

    /** Uma ligação não pode conectar um bloco a ele mesmo — bloqueia religar pra ponta oposta da mesma ligação. */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $integration = $this->route('integration');
            $edgeIndex = (int) $this->route('edge');
            $edge = $integration?->chain['edges'][$edgeIndex] ?? null;

            if (! $edge || ! $this->filled('end') || ! $this->filled('node')) {
                return;
            }

            $otherEnd = $this->input('end') === 'from' ? 'to' : 'from';
            if ((int) $this->input('node') === (int) ($edge[$otherEnd] ?? -1)) {
                $validator->errors()->add('node', 'Uma ligação não pode conectar um bloco a ele mesmo.');
            }
        });
    }
}
