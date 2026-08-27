<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which solutions a caderno documents — the whole set, every time, because the
 * endpoint `sync()`s: what isn't sent is unlinked. A "toggle one link" endpoint
 * would need the client to know the current set anyway, and would drift from it
 * the moment two people edited the same caderno.
 *
 * An EMPTY set is valid and means "this caderno documents no solution in
 * particular" — the normal state of a cross-cutting process, and of every
 * GitBook space nobody has filed yet. `x-forms.chips` submits nothing at all
 * when its last chip is removed, so the absent key IS the empty set here; that
 * is deliberate, and it is what makes unlinking the last solution persist.
 * (The people form solves the same problem the other way, with a
 * `solutions_present` sentinel, because there a missing key has to be told
 * apart from "the field wasn't on this form".)
 */
class SyncNotebookSolutionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        return $notebook !== null && $this->user()->can('update', $notebook);
    }

    /**
     * `x-forms.chips` submits `solutions[i][value]` + `[label]` per chip — the
     * label is display-only and the value is the id. Flattening here is what
     * lets the rules below validate real ids instead of trusting a nested shape.
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
            'solutions.*.exists' => 'Uma das soluções escolhidas não existe mais.',
        ];
    }

    /** @return array<int, int> */
    public function solutionIds(): array
    {
        return array_map(intval(...), $this->validated()['solutions'] ?? []);
    }
}
