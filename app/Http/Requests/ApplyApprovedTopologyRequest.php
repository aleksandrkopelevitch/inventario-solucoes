<?php

namespace App\Http\Requests;

use App\Models\ApprovedTopology;
use App\Models\Diagram;
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

    /** The chosen target, or null for "create a new diagram". */
    public function targetDiagram(): ?Diagram
    {
        $id = $this->input('diagram_id');

        return filled($id) ? Diagram::find($id) : null;
    }
}
