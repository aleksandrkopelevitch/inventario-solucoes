<?php

namespace App\Support\Catalog;

use App\Enums\ContactType;
use App\Enums\PersonSolutionRole;
use App\Models\Person;
use App\Rules\AsciiSlug;
use Illuminate\Validation\Rule;

/**
 * The validation rules of a whole person record, shared by the catalog's side
 * panel (`Store/UpdatePersonRequest`) and the MCP write tools — see
 * `SolutionRules` for why they live outside the Form Requests.
 */
final class PersonRules
{
    /** Keys only an HTTP form sends: the photo upload and the form's two repeaters. */
    public const FORM_ONLY_KEYS = ['photo', 'photo_action', 'solutions', 'contacts'];

    /** @return array<string, mixed> */
    public static function for(?Person $person = null): array
    {
        $unique = Rule::unique('people', 'slug');

        return [
            'name'       => ['required', 'string', 'max:255'],
            'slug'       => ['nullable', 'string', 'max:255', new AsciiSlug, $person ? $unique->ignore($person->getKey()) : $unique, Rule::notIn(Person::RESERVED_SLUGS)],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'job_title'  => ['nullable', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'notes'      => ['nullable', 'string'],
            'photo'      => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // `<x-forms.image-upload>`'s "Remover" button writes this hidden
            // field; it was never validated (so never read), which is why the
            // button silently did nothing. See `PersonController::payload()`.
            'photo_action'      => ['nullable', 'string', 'in:remove'],
            'solutions'         => ['nullable', 'array'],
            'solutions.*.value' => ['required', 'string'],
            'solutions.*.label' => ['nullable', 'string'],
            'solutions.*.role'  => ['nullable', Rule::enum(PersonSolutionRole::class)],
            // Additional contacts (`Person::contacts()`, besides the single
            // email/phone fields above) — a blank row (added in the form but
            // never filled in) is filtered out in the controller, not here.
            'contacts'        => ['nullable', 'array'],
            'contacts.*.type' => ['nullable', Rule::enum(ContactType::class)],
            // `id`, when present, must belong to THIS person (the
            // `where('person_id', ...)` below) — without that, a forged id in
            // the form could reassign another person's contact. A new person
            // has no contacts yet, so it gets no id rule at all.
            ...($person ? [
                'contacts.*.id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('person_id', $person->getKey())],
            ] : []),
            'contacts.*.value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
