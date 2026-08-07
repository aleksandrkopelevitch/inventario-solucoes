<?php

namespace App\Http\Requests;

use App\Enums\CompanyKind;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:255'],
            'slug'    => ['nullable', 'string', 'max:255', Rule::unique('companies', 'slug')],
            'kind'    => ['required', Rule::enum(CompanyKind::class)],
            'website' => ['nullable', 'url', 'max:255'],
            'notes'   => ['nullable', 'string'],
            'logo'    => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
