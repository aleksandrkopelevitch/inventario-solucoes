<?php

namespace App\Support\Digibee;

/**
 * One resolved Digibee platform session: where to call, which realm, and the
 * two headers that authenticate it.
 *
 * A readonly value object rather than four strings passed around, so the
 * "never log this" rule has ONE place to live: `diagnose()` is the only way
 * anything here reaches a screen, and it reports field names, sources and
 * lengths — never a byte of the credential itself. A probe that printed the
 * JWT to help somebody debug would put a production-capable token into a
 * terminal scrollback, a CI log and, on this app, a `Log::debug` line.
 */
final class DigibeeCredentials
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $realm,
        public readonly string $jwt,
        public readonly string $apikey,
        /** Where each field came from, for `--diagnose`. Field => source label. */
        public readonly array $sources = [],
    ) {}

    /**
     * The headers §3.1 of the APLA spec names — and the scheme depends on
     * WHICH credential this is, which cost a round of 401s to learn.
     *
     * An interactive `digibeectl` session token goes in `Authorization` RAW:
     * prefixing it with `Bearer ` answers 401 on a perfectly valid token,
     * which is why nothing here uses Laravel's `withToken()`. A digibeectl
     * TOKEN — the scoped kind created in Administration → Digibeectl — is the
     * exact opposite: raw answers 401 and `Bearer ` answers 200. Measured
     * against the real realm on 2026-09-11, one GET per variant.
     *
     * The two are told apart by the token itself rather than by configuration,
     * because a flag would be one more thing to set correctly and its failure
     * mode is a silent 401 that reads as "the credential is wrong". A token
     * ACL carries `useTokenACL: true` in its payload; a session token does
     * not.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Authorization' => $this->usesTokenAcl() ? 'Bearer ' . $this->jwt : $this->jwt,
            'apikey'        => $this->apikey,
        ];
    }

    /**
     * Whether the JWT is a scoped digibeectl TOKEN rather than an interactive
     * session.
     *
     * Reads the payload without verifying the signature, deliberately: this is
     * not an authorization decision — the platform makes that — it only picks
     * which header shape to send. Anything unreadable falls back to the
     * session behaviour, which is what every credential before this one was.
     */
    public function usesTokenAcl(): bool
    {
        $parts = explode('.', $this->jwt);

        if (count($parts) !== 3) {
            return false;
        }

        $payload = json_decode(
            (string) base64_decode(strtr($parts[1], '-_', '+/'), true),
            true,
        );

        return is_array($payload) && ($payload['useTokenACL'] ?? false) === true;
    }

    /**
     * The permissions the token itself declares, as the platform scopes them
     * (`DEPLOYMENT:CREATE{ENV=TEST}`) — empty for an interactive session,
     * which carries the user's whole role instead.
     *
     * Worth surfacing in `--diagnose`: "the credential resolved" and "the
     * credential may do what you are about to ask" are different questions,
     * and the second one is answerable offline, before a 403 answers it in
     * production.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        $parts = explode('.', $this->jwt);

        if (count($parts) !== 3) {
            return [];
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $roles = is_array($payload) ? ($payload['roles'] ?? []) : [];

        return is_array($roles) ? array_values(array_filter($roles, is_string(...))) : [];
    }

    /** @return list<string> the fields that resolved to nothing */
    public function missing(): array
    {
        return array_values(array_keys(array_filter([
            'endpoint' => $this->endpoint === '',
            'realm'    => $this->realm === '',
            'jwt'      => $this->jwt === '',
            'apikey'   => $this->apikey === '',
        ])));
    }

    public function complete(): bool
    {
        return $this->missing() === [];
    }

    /**
     * What resolved, from where, and how long it is — the whole diagnostic
     * surface. Lengths are here because they are the one property of a
     * credential worth seeing: a JWT truncated by a shell quoting mistake is
     * indistinguishable from a valid one at every other layer, and answers 401
     * with the same message an expired token does.
     *
     * @return list<array{field: string, resolved: bool, source: string, length: int}>
     */
    public function diagnose(): array
    {
        $rows = [];

        foreach (['endpoint' => $this->endpoint, 'realm' => $this->realm, 'jwt' => $this->jwt, 'apikey' => $this->apikey] as $field => $value) {
            $rows[] = [
                'field'    => $field,
                'resolved' => $value !== '',
                'source'   => $this->sources[$field] ?? '—',
                'length'   => mb_strlen($value),
            ];
        }

        return $rows;
    }
}
