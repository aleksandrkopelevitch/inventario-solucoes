<?php

namespace App\Http\Requests;

use App\Enums\ChainNodeKind;
use App\Http\Requests\Concerns\AuthorizesChainOwner;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A whole earlier state of the canvas — chain AND layout — written back in one
 * go. It is what Ctrl+Z / Ctrl+Y send when the step being undone changed the
 * topology (a block added or deleted, an arrow created, a block renamed): those
 * were written to the server the moment they happened, so undoing one locally
 * would leave the canvas showing a drawing the database no longer has.
 *
 * The state comes from the canvas itself, which recorded it from the server's
 * own answers (every chain mutation returns the stored `chain`). It is still
 * validated as untrusted input, field by field, because it is: nothing stops a
 * client from posting any chain it likes here, so this checks everything the
 * other single-purpose requests check between them, plus the two things only
 * a whole state can get wrong — an edge pointing past the last node, and a
 * layout whose arrays do not line up with the chain they describe (keyed by
 * index, they would silently hand one block's position to another).
 *
 * What it deliberately does not trust is in `EditsChain::restoreChain()`: the
 * root node is kept as stored, and a `media_id` that is not one of this owner's
 * pictures is dropped.
 */
class RestoreChainRequest extends FormRequest
{
    use AuthorizesChainOwner;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $layout = collect(SaveChainLayoutRequest::layoutRules())
            ->mapWithKeys(fn (array $rules, string $key) => ['layout.' . $key => $rules])
            ->all();

        return [
            'chain'       => ['required', 'array'],
            'chain.nodes' => ['required', 'array', 'min:1', 'max:500'],
            // `kind` may be absent: a node written before kinds existed has
            // none, and reads as a system (`ChainNodeKind::fromNode()`).
            'chain.nodes.*.kind'        => ['nullable', Rule::enum(ChainNodeKind::class)],
            'chain.nodes.*.solution_id' => ['nullable', 'integer', 'exists:solutions,id'],
            'chain.nodes.*.label'       => ['nullable', 'string', 'max:255'],
            'chain.nodes.*.media_id'    => ['nullable', 'integer'],
            'chain.edges'               => ['present', 'array', 'max:2000'],
            'chain.edges.*.from'        => ['required', 'integer', 'min:0'],
            'chain.edges.*.to'          => ['required', 'integer', 'min:0'],
            'chain.edges.*.arrow'       => ['required', Rule::in(['->', '<-', '<->'])],
            'chain.edges.*.protocol'    => ['nullable', 'string', 'max:60'],
            'layout'                    => ['required', 'array'],
            ...$layout,
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $nodeCount = count($this->input('chain.nodes', []));
                $edges = $this->input('chain.edges', []);

                foreach ($edges as $i => $edge) {
                    [$from, $to] = [(int) $edge['from'], (int) $edge['to']];

                    if ($from >= $nodeCount || $to >= $nodeCount || $from === $to) {
                        $validator->errors()->add("chain.edges.{$i}", 'A ligação aponta para um bloco que não existe.');
                    }
                }

                if (count($this->input('layout.nodes', [])) !== $nodeCount || count($this->input('layout.edges', [])) !== count($edges)) {
                    $validator->errors()->add('layout', 'O layout não corresponde ao desenho.');
                }
            },
        ];
    }
}
