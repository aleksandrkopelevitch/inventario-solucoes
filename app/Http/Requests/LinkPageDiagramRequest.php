<?php

namespace App\Http\Requests;

use App\Models\DocumentationPage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Points a documentation page at a diagram, or clears the link.
 *
 * `nullable` is the whole "clear" gesture: the top bar's picker renders a blank
 * option, and choosing it unlinks. Nothing is deleted either way — a page and a
 * diagram are two records that reference each other, so removing the reference
 * leaves both.
 *
 * Authorized against the CONTAINER, not the page: whether someone may edit a
 * page has always been a question about the solution or group it belongs to
 * (`DocumentationPagePolicy`), and pointing it at a drawing is an edit like any
 * other. It deliberately does NOT also require permission on the diagram —
 * linking reads a diagram, it never writes one.
 */
class LinkPageDiagramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $page = $this->route('page');

        return $page instanceof DocumentationPage
            && ($this->user()?->can('update', $page) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'diagram_id' => ['present', 'nullable', 'integer', 'exists:diagrams,id'],
        ];
    }
}
