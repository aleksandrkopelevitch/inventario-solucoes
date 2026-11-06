<?php

namespace App\Http\Requests;

use App\Models\DigibeeMonitorSession;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The session the "Enviar sessão ao iSol" bookmarklet hands over: the JWT the
 * Digibee web app holds and its public `apikey`. Admin only — it decides whose
 * Digibee identity the server reads with. The shape is checked here; whether
 * the token is any good is `ImportDigibeeSession`'s question.
 */
class ImportDigibeeSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DigibeeMonitorSession::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'token'  => ['required', 'string', 'max:8000', 'regex:/^[\w-]+\.[\w-]+\.[\w-]+$/'],
            'apikey' => ['required', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required'  => 'O favorito não trouxe a sessão da Digibee. Entre na Digibee e clique nele de novo.',
            'token.regex'     => 'O que chegou da Digibee não parece uma sessão. Entre na Digibee e clique no favorito de novo.',
            'apikey.required' => 'O favorito não trouxe a chave do app da Digibee. Entre na Digibee e clique nele de novo.',
        ];
    }
}
