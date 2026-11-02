<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Já refletida no catálogo" — closing an approved TO BE without applying it.
 *
 * The reason was read off a plain Request, so nothing bounded it, and the
 * column is a varchar(255): a longer note was a 500 on Postgres.
 */
class DismissApprovedTopologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.max' => 'O motivo pode ter no máximo 255 caracteres.',
        ];
    }
}
