<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Retargets one end (`from` or `to`) of an existing chain edge to another
 * node — dragging the arrow's handle in data-viz F3 to any other block
 * (`integration-viz.js::retargetEdge()`). This is what enables the free
 * graph: the edge is no longer stuck to the pair of nodes it was created with.
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

    /** An edge cannot connect a block to itself — blocks retargeting to the opposite end of the same edge. */
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
