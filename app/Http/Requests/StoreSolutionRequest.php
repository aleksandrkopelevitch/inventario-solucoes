<?php

namespace App\Http\Requests;

use App\Models\Solution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Solution::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'                   => ['required', 'string', 'max:255'],
            'slug'                   => ['nullable', 'string', 'max:255', Rule::unique('solutions', 'slug')],
            'description'            => ['nullable', 'string'],
            'vendor_company_id'      => ['nullable', 'integer', 'exists:companies,id'],
            'category'               => ['required', Rule::exists('attribute_options', 'value')->where('group', 'category')],
            'directorate'            => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'directorate')],
            'support_type'           => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'support_type')],
            'environment'            => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'environment')],
            'cloud'                  => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'cloud')],
            'contract_status'        => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'contract_status')],
            'support_operation_note' => ['nullable', 'string'],
            'criticality'            => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'criticality')],
            'status'                 => ['required', Rule::exists('attribute_options', 'value')->where('group', 'status')],
            'logo'                   => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
