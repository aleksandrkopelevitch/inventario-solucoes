<?php

namespace App\Support\Digibee\Testing;

use App\Enums\DigibeeTriggerAuth;

/**
 * What a caller needs to get past a deployed pipeline's own trigger — and it
 * is NOT the credential the design API uses.
 *
 * The two are unrelated on purpose: the design credential writes pipelines,
 * this one consumes an endpoint the way a partner system does (Basic Auth, an
 * API key, or a JWT the platform minted). Reaching for the design token here
 * would send a realm-wide credential to whatever host the environment resolves
 * to.
 *
 * It is never read from configuration. A deployed pipeline's consumer
 * credential belongs to whoever owns that integration, so it is handed in per
 * run and nothing here stores it.
 */
final readonly class EndpointCredential
{
    /** @param array<string, string> $headers */
    private function __construct(
        public DigibeeTriggerAuth $kind,
        private array $headers,
    ) {}

    public static function basic(string $user, string $password): self
    {
        return new self(
            DigibeeTriggerAuth::BasicAuth,
            ['Authorization' => 'Basic ' . base64_encode("{$user}:{$password}")],
        );
    }

    /**
     * The header name is a parameter because the platform does not fix one:
     * the tenant's own specs carry `X-api-key` and `X-Api-Key` in the same
     * corpus, and a gateway that matches case-sensitively answers 401 to the
     * wrong spelling with no explanation.
     */
    public static function apiKey(string $value, string $header = 'x-api-key'): self
    {
        return new self(DigibeeTriggerAuth::KeyAuth, [$header => $value]);
    }

    public static function jwt(string $token): self
    {
        return new self(DigibeeTriggerAuth::Jwt, ['Authorization' => 'Bearer ' . $token]);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
