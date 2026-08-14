<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Retypes ONE additional contact's value (`Person::contacts()`) in place from
 * the person's detail header. Only the value: the contact's `type`, adding a
 * row and removing one all stay in the full edit panel's repeater
 * (`data-ak-contacts`), which is where those gestures already live.
 *
 * The contact is resolved through a scoped binding (`->scopeBindings()` on the
 * route), so a contact id belonging to another person 404s instead of being
 * retargeted.
 */
class UpdatePersonContactRequest extends FormRequest
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
            // A contact with no value is a contact that shouldn't exist —
            // removing it is the panel's job, not a blank confirm here.
            'value' => ['required', 'string', 'max:255'],
        ];
    }
}
