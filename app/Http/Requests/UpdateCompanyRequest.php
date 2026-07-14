<?php

namespace App\Http\Requests;

use App\Enums\CompanyKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
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
        $companyId = $this->route('company')->id;

        return [
            'name'    => ['required', 'string', 'max:255'],
            'slug'    => ['nullable', 'string', 'max:255', Rule::unique('companies', 'slug')->ignore($companyId)],
            'kind'    => ['required', Rule::enum(CompanyKind::class)],
            'website' => ['nullable', 'url', 'max:255'],
            'notes'   => ['nullable', 'string'],
            'logo'    => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
        ];
    }
}
