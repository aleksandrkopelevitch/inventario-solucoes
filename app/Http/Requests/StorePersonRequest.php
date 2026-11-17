<?php

namespace App\Http\Requests;

use App\Models\Person;
use App\Support\Catalog\PersonRules;
use Illuminate\Foundation\Http\FormRequest;

class StorePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Person::class) ?? false;
    }

    /**
     * Shared with the MCP write tools — see `PersonRules`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return PersonRules::for();
    }
}
