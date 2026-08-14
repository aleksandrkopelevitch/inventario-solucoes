<?php

namespace App\Http\Requests;

use App\Enums\CompanyKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updates ONE of a company's fields in isolation — edited in place on the
 * detail header (`Companies\DetailHeader` + `<x-ui.inline-edit>`), without
 * opening the full edit panel. Unlike `UpdateCompanyRequest` (the whole form,
 * where `name`/`kind` are always required), every field here is `sometimes`:
 * the header only sends the one the user just confirmed.
 *
 * `slug` deliberately isn't editable from here, and renaming the company
 * doesn't regenerate it — the detail page's own URL would change underneath
 * the request that's rendering it.
 *
 * Sibling of `UpdatePersonFieldRequest` / `UpdateSolutionFieldRequest`; the
 * three follow the same shape on purpose.
 */
class UpdateCompanyFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('company')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // NOT NULL in the schema — can never be emptied out from here.
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'kind' => ['sometimes', 'required', Rule::enum(CompanyKind::class)],
            // Nullable: the header shows a placeholder and accepts clearing
            // back to null. No `max` on the text column beyond the panel's own
            // rules, so a value the panel accepted can always be re-saved here.
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'notes'   => ['sometimes', 'nullable', 'string'],
            // Same rule as the six Store/Update image rules across the app —
            // `avatar-upload.js`'s ACCEPTED_IMAGE_MIMES mirrors it client-side.
            'logo' => ['sometimes', 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // `<x-forms.image-upload>`'s "Remover" writes this hidden input
            // (`{name}_action`).
            'logo_action' => ['sometimes', 'nullable', 'string', 'in:remove'],
        ];
    }

    /**
     * The editor sends JSON null for an emptied field, but a multipart request
     * (the logo) can only carry strings — normalise both to null so a blank
     * value never lands in the column as ''.
     */
    protected function prepareForValidation(): void
    {
        foreach (['website', 'notes', 'logo_action'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filled($this->input($field)) ? $this->input($field) : null]);
            }
        }
    }
}
