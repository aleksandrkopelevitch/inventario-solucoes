<?php

namespace App\Http\Requests;

use App\Enums\ContactType;
use App\Enums\PersonSolutionRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePersonRequest extends FormRequest
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
        $personId = $this->route('person')->id;

        return [
            'name'              => ['required', 'string', 'max:255'],
            'slug'              => ['nullable', 'string', 'max:255', Rule::unique('people', 'slug')->ignore($personId)],
            'company_id'        => ['nullable', 'integer', 'exists:companies,id'],
            'job_title'         => ['nullable', 'string', 'max:255'],
            'email'             => ['nullable', 'email', 'max:255'],
            'phone'             => ['nullable', 'string', 'max:50'],
            'notes'             => ['nullable', 'string'],
            'photo'             => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'solutions'         => ['nullable', 'array'],
            'solutions.*.value' => ['required', 'string'],
            'solutions.*.label' => ['nullable', 'string'],
            'solutions.*.role'  => ['nullable', Rule::enum(PersonSolutionRole::class)],
            // Contatos adicionais (`Person::contacts()`, além dos campos
            // únicos email/phone acima) — linha em branco (adicionada no
            // form mas nunca preenchida) é filtrada no controller, não aqui.
            // `id`, quando presente, precisa pertencer a ESTA pessoa (o
            // `where('person_id', ...)` do Rule::exists abaixo) — sem isso,
            // um id forjado no form conseguiria reatribuir o contato de
            // outra pessoa.
            'contacts'         => ['nullable', 'array'],
            'contacts.*.id'    => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('person_id', $personId)],
            'contacts.*.type'  => ['nullable', Rule::enum(ContactType::class)],
            'contacts.*.value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
