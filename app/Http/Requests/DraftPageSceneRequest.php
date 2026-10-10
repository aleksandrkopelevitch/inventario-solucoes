<?php

namespace App\Http\Requests;

use App\Models\Notebook;
use App\Support\Documentation\StepScene;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Gerar a partir da página" on a scene block of the editor.
 *
 * Authorized like saving the page (`update` on the caderno): the proposal is
 * only ever dropped into a block of a page this person is editing, and is
 * saved by the editor's own save.
 *
 * `content` is the EDITOR's current Markdown. Optional — without it the saved
 * page is read — but the editor always sends it, because the paragraph the
 * author just wrote is usually the one the figure is meant to show, and
 * autosave may not have reached it yet. Same ceiling as a save.
 */
class DraftPageSceneRequest extends FormRequest
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
            // One scene type today; the field exists so the next one is a new
            // value here rather than a new endpoint.
            'type'    => ['required', 'string', Rule::in([StepScene::TYPE])],
            'focus'   => ['nullable', 'string', 'max:300'],
            'content' => ['nullable', 'string', 'max:500000'],
        ];
    }
}
