<?php

namespace App\Http\Requests;

use App\Models\DigibeeMonitorSession;
use Illuminate\Foundation\Http\FormRequest;

/** Opening the server's browser on the Digibee SSO link. Admin only. */
class StartDigibeeSignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DigibeeMonitorSession::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
