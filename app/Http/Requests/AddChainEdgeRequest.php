<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Contracts\Validation\Validator;
use App\Http\Requests\Concerns\AuthorizesChainOwner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates a new edge between two blocks already present in the chain — dragging
 * an arrow out of a block's port, or the data-viz F3 "connect mode" (click one
 * block, then another): unlike `AddChainNodeRequest` (which always
 * appends a new node) and `RetargetChainEdgeRequest` (which moves the
 * end of an existing edge), this endpoint adds a new edge without touching the
 * nodes — that's what makes the chain a genuinely free graph, letting any pair
 * of already drawn blocks be connected.
 *
 * Two edges between the SAME pair are legitimate as long as they say something
 * different (A -> B over REST *and* over SFTP), so only an exact duplicate
 * (same from/to/arrow/protocol) is refused — see `after()`.
 */
class AddChainEdgeRequest extends FormRequest
{
    use AuthorizesChainOwner;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Resolved by TYPE, never by parameter name: this request serves both
        // the integration canvas and a submission's drawing, and a by-name
        // lookup returns null on the other owner — which silently collapses
        // `$max` to 0 and rejects every edge past the root as "out of range".
        // A rule that fails on the WORKING path reads as a broken canvas.
        $max = max(0, count($this->chainOwner()?->chainData()['nodes'] ?? []) - 1);

        return [
            'from'  => ['required', 'integer', 'min:0', 'max:' . $max],
            'to'    => ['required', 'integer', 'min:0', 'max:' . $max, 'different:from'],
            'arrow' => ['required', Rule::in(['->', '<-', '<->'])],
            // Free text, not just `App\Enums\Protocol` values — see
            // `UpdateChainProtocolRequest` for the same rule.
            'protocol' => ['nullable', 'string', 'max:60'],
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

                $duplicate = collect($this->chainOwner()?->chainData()['edges'] ?? [])
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
