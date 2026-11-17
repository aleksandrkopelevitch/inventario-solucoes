<?php

namespace App\Http\Requests;

use App\Support\Catalog\CompanyRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('company')) ?? false;
    }

    /**
     * Shared with the MCP write tools — see `CompanyRules`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CompanyRules::for($this->route('company'));
    }
}
