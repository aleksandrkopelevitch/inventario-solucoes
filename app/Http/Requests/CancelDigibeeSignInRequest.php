<?php

namespace App\Http\Requests;

use App\Models\DigibeeMonitorSession;
use Illuminate\Foundation\Http\FormRequest;

/** Closing the server's browser before the sign-in finished. Admin only. */
class CancelDigibeeSignInRequest extends FormRequest
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
