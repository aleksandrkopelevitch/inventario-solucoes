<?php

namespace App\Http\Requests;

use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A caderno's name — used both to create and to rename (the slug never changes,
 * for the usual reason: a caderno's URL is stable).
 *
 * The solutions it documents do not come through here; they have an endpoint of
 * their own (`notebooks.solutions`, SyncNotebookSolutionsRequest), because
 * linking is a decision apart from naming and each answers with a different set
 * of slots.
 */
class SaveNotebookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        return $notebook ? $this->user()->can('update', $notebook) : $this->user()->can('create', Notebook::class);
    }

    /** @return array<string, array<int, string>> */
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
            'name.required' => 'Dê um nome ao caderno.',
        ];
    }
}
