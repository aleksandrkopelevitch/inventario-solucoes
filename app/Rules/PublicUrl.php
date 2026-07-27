<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Blocks a URL whose host resolves to a private/loopback/link-local address
 * — RFC1918, 127.0.0.0/8, 169.254.0.0/16 (includes the cloud metadata
 * endpoint 169.254.169.254), and their IPv6 equivalents — before the app
 * makes a server-side request to it. `EditsDocumentation::
 * storeDocumentationMedia()`'s "paste image URL" path calls Spatie's
 * `addMediaFromUrl()`, which only checks the URL starts with http(s):// —
 * no network-range guard — so without this rule an admin (the only role
 * that reaches this endpoint) could make the server fetch an internal-only
 * URL.
 *
 * This resolves DNS at validation time, so it does NOT close a DNS-rebinding
 * race (attacker's DNS answers public here, private moments later when the
 * actual fetch happens) — accepted as a documented residual risk, not
 * something this rule claims to solve.
 */
class PublicUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? parse_url($value, PHP_URL_HOST) : null;

        if (! $host) {
            $fail('A URL informada é inválida.');

            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if ($ips === []) {
            $fail('Não foi possível resolver o endereço da URL informada.');

            return;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $fail('Essa URL aponta para um endereço de rede interno, o que não é permitido.');

                return;
            }
        }
    }

    /** @return array<int, string> */
    private function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A + DNS_AAAA) ?: [];

        return collect($records)
            ->map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter()
            ->values()
            ->all();
    }
}
