<?php

namespace App\Http\Requests;

use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publishing a caderno into the internal knowledge base, or taking it back out.
 *
 * `administer`, not `update`, and it is the same split the magic link already
 * answers to: an editor writes the pages, an admin decides who the caderno is
 * in front of. This one reaches further than the magic link does, in fact —
 * a link has to be handed to somebody, while `/docs` is a place several hundred
 * people can wander into.
 */
class PublishNotebookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        return $notebook instanceof Notebook
            && ($this->user()?->can('administer', $notebook) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'published' => ['required', 'boolean'],
        ];
    }

    public function shouldPublish(): bool
    {
        return $this->boolean('published');
    }
}
