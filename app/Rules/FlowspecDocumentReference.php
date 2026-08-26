<?php

namespace App\Rules;

use App\Models\DocumentationPage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a `page:{id}` reference coming from the flowSpec document picker.
 *
 * `page` is the only prefix there is — `diagram:{id}` went away with
 * integration documentation, since a drawing has no text to attach (see
 * `App\Enums\FlowspecDocumentType`). The prefix stays in the wire format
 * because the reference is stored as a morph pair.
 */
class FlowspecDocumentReference implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^page:(\d+)$/', $value, $match)) {
            $fail('Referência de documento inválida.');

            return;
        }

        if (! DocumentationPage::query()->whereKey($match[1])->exists()) {
            $fail('O documento referenciado não existe.');
        }
    }
}
