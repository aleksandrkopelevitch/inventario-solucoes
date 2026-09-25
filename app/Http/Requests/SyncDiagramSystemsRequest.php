<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The systems somebody declares a drawing concerns — the whole set, every time,
 * because the endpoint replaces it. Same shape and same reasoning as
 * `SyncNotebookSolutionsRequest`, down to the empty set: `x-forms.chips` sends
 * nothing at all once its last chip is removed, so the absent key IS "no system
 * declared", which is what makes removing the last one persist.
 *
 * It only ever writes the MANUAL half of the pivot (`SetDiagramSystems`) — the
 * derived half belongs to the chain, and nothing a client sends can reach it.
 */
class SyncDiagramSystemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $diagram = $this->route('diagram');

        return $diagram !== null && $this->user()->can('update', $diagram);
    }

    /**
     * `x-forms.chips` submits `solutions[i][value]` + `[label]` per chip — the
     * label is display-only and the value is the id.
     */
    protected function prepareForValidation(): void
    {
        $rows = $this->input('solutions');

        if (! is_array($rows)) {
            return;
        }

        $this->merge([
            'solutions' => array_values(array_filter(array_map(
                fn ($row) => is_array($row) ? ($row['value'] ?? null) : $row,
                $rows,
            ), fn ($value) => is_numeric($value))),
        ]);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'solutions'   => ['nullable', 'array'],
            'solutions.*' => ['integer', 'exists:solutions,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'solutions.*.exists' => 'Um dos sistemas escolhidos não existe mais.',
        ];
    }

    /** @return array<int, int> */
    public function solutionIds(): array
    {
        return array_map(intval(...), $this->validated()['solutions'] ?? []);
    }
}
