<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Updates ONE of a solution's own fields in isolation — edited in place on the
 * detail header (`Solutions\DetailHeader` + `<x-ui.inline-edit>`), without
 * opening the full edit panel.
 *
 * The header's OTHER inline editors — the 8 attribute badges (Categoria,
 * Status, …) — go to `solutions.attributes.update` /
 * `UpdateSolutionAttributesRequest` instead: those are `attribute_options`
 * values validated per group, a different mechanism
 * (`solution-attributes.js`, auto-save on `change`) that predates this one.
 * Fields here are the solution's own columns.
 *
 * `slug` deliberately isn't editable from here, and renaming the solution
 * doesn't regenerate it — the detail page's own URL would change underneath
 * the request that's rendering it.
 */
class UpdateSolutionFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('solution')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // NOT NULL in the schema — can never be emptied out from here.
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Nullable: the header shows a placeholder and accepts clearing
            // back to null. No `max` on the text columns beyond the panel's own
            // rules, so a value the panel accepted can always be re-saved here.
            'description'            => ['sometimes', 'nullable', 'string'],
            'vendor_company_id'      => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'support_operation_note' => ['sometimes', 'nullable', 'string'],
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
        foreach (['description', 'vendor_company_id', 'support_operation_note', 'logo_action'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filled($this->input($field)) ? $this->input($field) : null]);
            }
        }
    }
}
