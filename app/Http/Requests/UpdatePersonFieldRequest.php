<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Updates ONE of a person's fields in isolation — edited in place on the
 * detail header (`People\DetailHeader` + `<x-ui.inline-edit>`), without
 * opening the full edit panel. Unlike `UpdatePersonRequest` (the whole form,
 * where `name` is always required), every field here is `sometimes`: the
 * header only sends the one the user just confirmed.
 *
 * `slug` deliberately isn't editable from here, and renaming the person
 * doesn't regenerate it — the detail page's own URL would change underneath
 * the request that's rendering it.
 */
class UpdatePersonFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('person')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // NOT NULL in the schema — can never be emptied out from here.
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Nullable: the header shows a placeholder and accepts clearing back to null.
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'job_title'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'email'      => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'      => ['sometimes', 'nullable', 'string', 'max:50'],
            // No `max` beyond the panel's own rule (`UpdatePersonRequest`), so
            // a value the panel accepted can always be re-saved from here.
            'notes' => ['sometimes', 'nullable', 'string'],
            // Same rule as the six Store/Update image rules across the app —
            // `avatar-upload.js`'s ACCEPTED_IMAGE_MIMES mirrors it client-side.
            'photo' => ['sometimes', 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // `<x-forms.image-upload>`'s "Remover" writes this hidden input
            // (`{name}_action`) — the mechanism existed app-wide but nothing
            // ever read it, so the button was inert everywhere.
            'photo_action' => ['sometimes', 'nullable', 'string', 'in:remove'],
        ];
    }

    /**
     * The editor sends JSON null for an emptied field, but a multipart request
     * (the photo) can only carry strings — normalise both to null so a blank
     * value never lands in the column as ''.
     */
    protected function prepareForValidation(): void
    {
        foreach (['company_id', 'job_title', 'email', 'phone', 'notes', 'photo_action'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filled($this->input($field)) ? $this->input($field) : null]);
            }
        }
    }
}
