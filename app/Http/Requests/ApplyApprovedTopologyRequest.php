<?php

namespace App\Http\Requests;

use App\Models\ApprovedTopology;
use App\Models\Diagram;
use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Choosing where an approved TO BE lands.
 *
 * `diagram_id` empty means "create a new one" — the common case for a
 * proposal, which usually describes something the catalog does not have yet.
 *
 * When it names an existing diagram, the SOLUTION must be a participant in it.
 * Without that check, an approval on one solution could overwrite the topology
 * of a diagram belonging to another — a write nobody would ever look for, on a
 * record nobody involved was editing.
 */
class ApplyApprovedTopologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'diagram_id' => ['nullable', 'integer', 'exists:diagrams,id'],
            // Where a NEW diagram goes — a diagram always belongs to a caderno.
            // Ignored when drawing over an existing one, which keeps its own.
            'notebook_id' => ['required_without:diagram_id', 'nullable', 'integer', 'exists:notebooks,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'notebook_id.required_without' => 'Escolha o caderno onde o novo diagrama vai ficar.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $diagram = $this->targetDiagram();

            if ($diagram === null) {
                return;
            }

            $topology = $this->route('topology');

            if (! $topology instanceof ApprovedTopology) {
                return;
            }

            if (! $diagram->participants()->whereKey($topology->solution_id)->exists()) {
                $validator->errors()->add(
                    'diagram_id',
                    'Esse diagrama não é da solução desta submissão.',
                );
            }
        });
    }

    /** The caderno a new diagram goes into; null when drawing over an existing one. */
    public function targetNotebook(): ?Notebook
    {
        if ($this->targetDiagram() !== null) {
            return null;
        }

        $id = $this->validated('notebook_id');

        return filled($id) ? Notebook::find($id) : null;
    }

    /** The chosen target, or null for "create a new diagram". */
    public function targetDiagram(): ?Diagram
    {
        $id = $this->input('diagram_id');

        return filled($id) ? Diagram::find($id) : null;
    }
}
