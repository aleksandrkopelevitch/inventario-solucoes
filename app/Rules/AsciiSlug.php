<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * A slug is lowercase ASCII letters, digits and single hyphens. Nothing else —
 * no accents, no `ç`, no spaces, no underscores, no leading or trailing dash.
 *
 * Everything that GENERATES a slug here already satisfies that, because
 * `Str::slug()` transliterates (`Soluções` becomes `solucoes`). The hole was
 * everything that ACCEPTS one: six Form Requests took `slug` as
 * `string|max:255|unique` and nothing else, so a posted `Soluções & Cia` would
 * have been stored verbatim and become part of a URL.
 *
 * Why it matters beyond tidiness: a slug is an ADDRESS. It travels through
 * route model binding, gets percent-encoded in some clients and not others,
 * is compared byte-for-byte by `PublicDocumentationController::diagramPicture()`
 * (authorisation, deliberately not folded — see AGENTS.md § Searching), and is
 * pasted into documentation as `page:{slug}` and `{% diagram slug="…" %}`.
 * Every one of those is a place where `operações` and `operacoes` are two
 * different strings that look like one.
 */
class AsciiSlug implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return; // `nullable` decides that, not this rule
        }

        if (! is_string($value) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value) !== 1) {
            $suggestion = is_string($value) ? Str::slug($value) : '';

            $fail(
                'O slug aceita apenas letras minúsculas sem acento, números e hífens.'
                . ($suggestion !== '' ? " Sugestão: \"{$suggestion}\"." : '')
            );
        }
    }
}
