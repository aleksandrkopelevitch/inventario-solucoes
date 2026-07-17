<?php

namespace App\Rules;

use App\Models\DocumentationPage;
use App\Models\Integration;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validates a `page:{id}`/`integration:{id}` reference coming from the flowSpec document chips picker. */
class FlowspecDocumentReference implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^(page|integration):(\d+)$/', $value, $match)) {
            $fail('Referência de documento inválida.');

            return;
        }

        [, $type, $id] = $match;

        $exists = $type === 'page'
            ? DocumentationPage::query()->whereKey($id)->exists()
            : Integration::query()->whereKey($id)->exists();

        if (! $exists) {
            $fail('O documento referenciado não existe.');
        }
    }
}
