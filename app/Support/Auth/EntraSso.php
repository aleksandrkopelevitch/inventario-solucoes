<?php

namespace App\Support\Auth;

use Illuminate\Support\Str;

/**
 * What the app knows about its own Entra configuration, in one place.
 *
 * Every screen that offers the "Entrar com Microsoft" button and every route
 * that would start the flow asks `configured()` first, because a half-filled
 * `.env` is the ordinary state of this feature: it ships with `enabled` off and
 * stays that way until somebody at IT creates the app registration. A button
 * that is rendered anyway leads to a Microsoft error page nobody can act on.
 */
final class EntraSso
{
    /**
     * Whether SSO can actually be attempted — switched on AND holding every
     * value the flow needs.
     *
     * The three are checked together rather than trusting the flag alone
     * because they fail differently: a missing `enabled` is a decision, a
     * missing `client_id` is an unfinished deploy, and both have to answer
     * "no button" rather than "a button that 500s".
     */
    public static function configured(): bool
    {
        $config = config('services.azure');

        return (bool) ($config['enabled'] ?? false)
            && filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null)
            && filled($config['tenant'] ?? null);
    }

    /** @return array<int, string> */
    public static function allowedDomains(): array
    {
        return array_map(
            fn (string $domain) => Str::lower(ltrim($domain, '@')),
            (array) config('services.azure.allowed_domains', []),
        );
    }

    /**
     * Whether `$email` belongs to a domain this app accepts from SSO.
     *
     * Exact match on the part after the LAST `@`, never `str_ends_with` on the
     * whole address: `…@evil-leomadeiras.com.br` ends with the allowed domain
     * as a string and is a different company. An empty allow-list refuses
     * everything rather than everything-goes — the failure mode of a mistyped
     * `ENTRA_ALLOWED_DOMAINS` should be that nobody gets in, not that anybody
     * does.
     */
    public static function allows(?string $email): bool
    {
        $domains = self::allowedDomains();

        if ($domains === [] || blank($email)) {
            return false;
        }

        $at = strrpos((string) $email, '@');

        if ($at === false) {
            return false;
        }

        return in_array(Str::lower(substr((string) $email, $at + 1)), $domains, true);
    }
}
