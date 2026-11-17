<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Support\Catalog\CompanyRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    /**
     * Shared with the MCP write tools — see `CompanyRules`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CompanyRules::for();
    }
}
