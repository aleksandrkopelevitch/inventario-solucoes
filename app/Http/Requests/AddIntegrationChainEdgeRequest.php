<?php

namespace App\Http\Requests;

use App\Enums\Protocol;
use App\Models\Integration;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Creates a new edge between two blocks already present in the chain — dragging
 * an arrow out of a block's port, or the data-viz F3 "connect mode" (click one
 * block, then another): unlike `AddIntegrationChainNodeRequest` (which always
 * appends a new node) and `RetargetIntegrationChainEdgeRequest` (which moves the
 * end of an existing edge), this endpoint adds a new edge without touching the
 * nodes — that's what makes the chain a genuinely free graph, letting any pair
 * of already drawn blocks be connected.
 *
 * Two edges between the SAME pair are legitimate as long as they say something
 * different (A -> B over REST *and* over SFTP), so only an exact duplicate
 * (same from/to/arrow/protocol) is refused — see `after()`.
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

    /**
     * Refuses an edge identical to one already in the chain. Dragging an arrow
     * out of a block's port creates `->` with no protocol and NO dialog on the
     * way, so repeating the gesture between the same two blocks (from another
     * port, say) is easy to do by accident — and the result is a second arrow
     * that says exactly what the first one already said, indistinguishable in
     * the canvas but double-counted in `SyncIntegrationFromChain`'s degrees.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // from/to/arrow already invalid — don't stack a confusing second error.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $duplicate = collect($this->route('integration')?->chain['edges'] ?? [])
                    ->contains(fn (array $edge): bool => ($edge['from'] ?? null) === $this->integer('from')
                        && ($edge['to'] ?? null) === $this->integer('to')
                        && ($edge['arrow'] ?? '->') === $this->input('arrow')
                        && ($edge['protocol'] ?? null) === $this->input('protocol'));

                if ($duplicate) {
                    $validator->errors()->add('to', 'Esses blocos já estão ligados assim — ajuste o sentido ou o protocolo na ligação que já existe.');
                }
            },
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
