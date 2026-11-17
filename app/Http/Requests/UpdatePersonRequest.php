<?php

namespace App\Http\Requests;

use App\Support\Catalog\PersonRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('person')) ?? false;
    }

    /**
     * Shared with the MCP write tools — see `PersonRules`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return PersonRules::for($this->route('person'));
    }
}
