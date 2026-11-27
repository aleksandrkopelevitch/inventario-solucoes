<?php

namespace App\Http\Requests;

use App\Models\Notebook;
use App\Support\Documentation\UmlDiagram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Gerar com IA" on a "Diagrama UML" block of the editor (UmlDraftService).
 *
 * Authorized like saving the page (`update` on the caderno): the proposal is
 * only ever dropped into a block of a page this person is editing, and saved
 * by the editor's own save.
 *
 * `content` is the EDITOR's current Markdown, as the scene block sends it
 * (the paragraph just written is usually the one the diagram is for).
 * `source` is the block's current JSON, sent only when it is not the untouched
 * skeleton: it makes the request a CHANGE to that diagram.
 */
class DraftPageUmlRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Notebook|null $notebook */
        $notebook = $this->route('notebook');

        return $notebook !== null && (bool) $this->user()?->can('update', $notebook);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type'        => ['required', 'string', Rule::in(array_keys(UmlDiagram::TYPES))],
            'title'       => ['nullable', 'string', 'max:500'],
            'instruction' => ['nullable', 'string', 'max:600'],
            'content'     => ['nullable', 'string', 'max:500000'],
            'source'      => ['nullable', 'string', 'max:500000'],
        ];
    }
}
