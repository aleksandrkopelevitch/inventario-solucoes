<?php

namespace App\Http\Requests;

use App\Models\McpToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Minting a token. Admin only — `McpTokenPolicy::create`, never `canWrite()`.
 */
class StoreMcpTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', McpToken::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Required, and that is the one rule worth having here: a token's
            // name is the only way to tell two of them apart once the plaintext
            // is gone, so an unnamed one can never be revoked with confidence.
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Dê um nome ao token — é como você vai saber qual apagar depois.',
        ];
    }
}
