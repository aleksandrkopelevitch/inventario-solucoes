<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updates one (or more) of a Solution's 8 attributes in isolation — edited in
 * place from the detail header card itself (`Solutions\DetailHeader`),
 * without opening the full edit panel. Unlike `UpdateSolutionRequest` (used
 * by the whole form, where `category`/`status` are always required), here
 * every field is `sometimes` — the card only sends the attribute the user
 * just changed.
 */
class UpdateSolutionAttributesRequest extends FormRequest
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
            'category'        => ['sometimes', 'required', Rule::exists('attribute_options', 'value')->where('group', 'category')],
            'status'          => ['sometimes', 'required', Rule::exists('attribute_options', 'value')->where('group', 'status')],
            'contract_status' => ['sometimes', 'required', Rule::exists('attribute_options', 'value')->where('group', 'contract_status')],
            'support_type'    => ['sometimes', 'required', Rule::exists('attribute_options', 'value')->where('group', 'support_type')],
            // Nullable in the schema — the card shows "Not informed" and accepts clearing back to null.
            'criticality' => ['sometimes', 'nullable', Rule::exists('attribute_options', 'value')->where('group', 'criticality')],
            'environment' => ['sometimes', 'nullable', Rule::exists('attribute_options', 'value')->where('group', 'environment')],
            'cloud'       => ['sometimes', 'nullable', Rule::exists('attribute_options', 'value')->where('group', 'cloud')],
            'directorate' => ['sometimes', 'nullable', Rule::exists('attribute_options', 'value')->where('group', 'directorate')],
        ];
    }

    /** Empties the select's "" sentinel to null, for the fields that accept it. */
    protected function prepareForValidation(): void
    {
        foreach (['criticality', 'environment', 'cloud', 'directorate'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filled($this->input($field)) ? (string) $this->input($field) : null]);
            }
        }
    }
}
