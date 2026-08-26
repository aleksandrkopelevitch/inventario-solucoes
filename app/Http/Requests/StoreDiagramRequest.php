<?php

namespace App\Http\Requests;

use App\Models\Diagram;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a new Diagram — a name (optional) and, when the gesture came from a
 * solution's detail page, that solution to seed the root block with. The
 * initial chain is `{nodes: [root], edges: []}`; blocks, edges and
 * rename/status are the canvas's and the top bar's job from then on.
 */
class StoreDiagramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Diagram::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'        => ['nullable', 'string', 'max:255'],
            'solution_id' => ['nullable', 'integer', 'exists:solutions,id'],
        ];
    }
}
