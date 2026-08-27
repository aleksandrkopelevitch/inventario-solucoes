<?php

namespace App\Http\Requests;

use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-files a page under another caderno. Mirror of MoveDocumentationPageRequest
 * (which reorders WITHIN one) — same authorize() shape.
 *
 * Note this only authorizes the SOURCE, via the route model. The destination is
 * a different record with its own policy, and a failure there has to be a 403,
 * not a 422 — so the controller resolves it and calls `authorize('update', …)`
 * on it separately. Don't fold that check into a validation rule.
 *
 * It used to carry a `solution:12` / `group:3` composite, split in
 * `prepareForValidation()`, because the destination could be either kind of
 * container. One kind means one integer, and the whole splitting apparatus
 * (plus the table-name switch it needed to validate `exists`) is gone.
 */
class MoveDocumentationPageToNotebookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        return $notebook !== null && $this->user()->can('update', $notebook);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'notebook' => [
                'required',
                'integer',
                'exists:notebooks,id',
                function (string $attribute, mixed $value, callable $fail): void {
                    // Moving a page to where it already is would pass every
                    // other rule and answer "Página movida." having done
                    // nothing — the rail hides the current caderno from the
                    // options, so reaching here means the request was forged or
                    // the rail was stale.
                    if ($this->destination()?->is($this->currentNotebook())) {
                        $fail('A página já está neste caderno.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'notebook.required' => 'Escolha o caderno de destino.',
            'notebook.integer'  => 'Destino inválido.',
            'notebook.exists'   => 'O caderno escolhido não existe mais.',
        ];
    }

    /** The caderno this page is being moved TO. */
    public function destination(): ?Notebook
    {
        $id = $this->input('notebook');

        return is_numeric($id) ? Notebook::find((int) $id) : null;
    }

    /** The caderno it is being moved FROM (the route model). */
    public function currentNotebook(): ?Notebook
    {
        return $this->route('notebook');
    }
}
