<?php

namespace App\Http\Requests;

use App\Enums\SceneType;
use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Gerar a partir da página" on a scene block of the editor (any SceneType).
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

    public function sceneType(): SceneType
    {
        return SceneType::from((string) $this->validated('type'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Which scene: a new kind is a new SceneType case, never a new
            // endpoint.
            'type'    => ['required', 'string', Rule::enum(SceneType::class)],
            'focus'   => ['nullable', 'string', 'max:300'],
            'content' => ['nullable', 'string', 'max:500000'],
        ];
    }
}
