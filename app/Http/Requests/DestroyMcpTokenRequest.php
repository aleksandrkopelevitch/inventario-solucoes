<?php

namespace App\Http\Requests;

use App\Models\McpToken;
use Illuminate\Foundation\Http\FormRequest;

/** Deleting a token — the only way to revoke one. Admin only. */
class DestroyMcpTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', McpToken::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
