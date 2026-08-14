<?php

namespace App\Http\Requests;

use App\Enums\ContactType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds ONE additional contact (`Person::contacts()`) straight from the person's
 * detail header — the "+ Adicionar contato" creator. The panel's repeater
 * (`data-ak-contacts`) still exists for bulk edits; this is the same record
 * created one at a time.
 */
class StorePersonContactRequest extends FormRequest
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
            'type'  => ['required', Rule::enum(ContactType::class)],
            'value' => ['required', 'string', 'max:255'],
        ];
    }
}
