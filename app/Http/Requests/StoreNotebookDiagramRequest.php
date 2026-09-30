<?php

namespace App\Http\Requests;

use App\Models\Diagram;
use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A blank diagram, created inside a caderno from its "Diagramas" modal — the
 * one way left to start a drawing by hand. It used to be `StoreDiagramRequest`,
 * posted from `/diagrams` and from a solution's page with no caderno at all.
 *
 * The name is REQUIRED now: a caderno's drawings are told apart by name in the
 * modal and in the "Desenhar esta página" dialog, and "Novo diagrama" twice is
 * two rows nobody can pick between.
 */
class StoreNotebookDiagramRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Notebook $notebook */
        $notebook = $this->route('notebook');

        return ($this->user()?->can('view', $notebook) ?? false)
            && $this->user()->can('create', Diagram::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Dê um nome ao diagrama.',
        ];
    }
}
