<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Layout visual da visualização gráfica F3 — posição de cada bloco (por índice
 * do nó da chain), a âncora de cada ponta de seta (por segmento) e o
 * comentário em markdown de cada bloco (também por índice do nó). É só
 * apresentação: não toca na topologia (a `chain` é a fonte de verdade), então
 * nada aqui vira participants/source/target/direction.
 */
class SaveIntegrationLayoutRequest extends FormRequest
{
    /** Âncoras possíveis: 4 principais + 2 no topo + 2 na base. */
    public const ANCHORS = ['l', 'r', 't', 'b', 'tl', 'tr', 'bl', 'br'];

    /** Fontes disponíveis na toolbar contextual de cada bloco. */
    public const FONTS = ['sans', 'serif', 'mono'];

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
            'nodes'             => ['present', 'array'],
            'nodes.*.x'         => ['required', 'numeric'],
            'nodes.*.y'         => ['required', 'numeric'],
            'nodes.*.color'     => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'nodes.*.textColor' => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'nodes.*.font'      => ['nullable', Rule::in(self::FONTS)],
            'edges'             => ['present', 'array'],
            'edges.*.from'      => ['required', Rule::in(self::ANCHORS)],
            'edges.*.to'        => ['required', Rule::in(self::ANCHORS)],
            'comments'          => ['sometimes', 'array'],
            'comments.*'        => ['nullable', 'string', 'max:4000'],
        ];
    }
}
