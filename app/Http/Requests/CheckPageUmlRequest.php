<?php

namespace App\Http\Requests;

use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The "Diagrama UML" block of the editor asking whether the diagram it
 * previews is one the page will draw (UmlDiagram is the one validator).
 *
 * Authorized like saving the page (`update` on the caderno): only somebody
 * editing the page has a block to check. `source` is the block's JSON as
 * typed, under the same ceiling as a page body.
 */
class CheckPageUmlRequest extends FormRequest
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
            'type'   => ['required', 'string', 'max:40'],
            'title'  => ['nullable', 'string', 'max:500'],
            'source' => ['required', 'string', 'max:500000'],
        ];
    }
}
