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
     * The headers §3.1 of the APLA spec names, and the shape matters: the JWT
     * goes in `Authorization` RAW, with no `Bearer ` prefix — which is why
     * nothing here uses Laravel's `withToken()`, since that would prepend one
     * and answer 401 on a token that is perfectly valid.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Authorization' => $this->jwt,
            'apikey'        => $this->apikey,
        ];
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
