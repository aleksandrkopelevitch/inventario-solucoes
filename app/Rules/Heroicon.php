<?php

namespace App\Rules;

use App\Support\Heroicons;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Valida que o valor é o slug (kebab-case) de um ícone outline existente no set heroicons. */
class Heroicon implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value)) {
            $fail('O ícone informado é inválido.');

            return;
        }

        if (! Heroicons::exists($value)) {
            $fail('O ícone informado não existe.');
        }
    }
}
