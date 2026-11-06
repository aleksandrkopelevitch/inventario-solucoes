<?php

namespace App\Http\Requests;

use App\Models\DigibeeMonitorSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One click, keystroke run or scroll for the server's sign-in browser.
 *
 * `text` is whatever the person typed — a password among it — so it is never
 * trimmed (bootstrap/app.php exempts this route) and never logged; a failed
 * validation answers with the rule's message, not the value.
 *
 * The bounds are the bridge's own: a 1024×720 viewport, the keys
 * `digibee-remote-sign-in.mjs` admits, 500 characters per run of typing.
 */
class SendDigibeeSignInInputRequest extends FormRequest
{
    public const KEYS = ['Enter', 'Tab', 'Backspace', 'Delete', 'Escape', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'Shift+Tab'];

    public function authorize(): bool
    {
        return $this->user()?->can('create', DigibeeMonitorSession::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['click', 'type', 'press', 'scroll'])],
            'x'    => ['required_if:type,click', 'integer', 'between:0,1024'],
            'y'    => ['required_if:type,click', 'integer', 'between:0,720'],
            // `present_if` + `min:1`, not `required_if`: `required` calls a
            // whitespace-only string empty, and a lone space is a real key.
            'text' => ['present_if:type,type', 'string', 'min:1', 'max:500'],
            'key'  => ['required_if:type,press', Rule::in(self::KEYS)],
            'dy'   => ['required_if:type,scroll', 'integer', 'between:-2000,2000'],
        ];
    }
}
