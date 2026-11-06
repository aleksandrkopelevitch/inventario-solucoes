<?php

namespace App\Support\Digibee\Monitor;

use Carbon\CarbonImmutable;

/**
 * The Digibee web session `digibee:monitor:login` captured off a browser
 * request: the two headers exactly as the Monitor screen sent them.
 *
 * Replayed verbatim rather than rebuilt, because the scheme is not ours to
 * decide — the web app sends `Bearer <jwt>`, while the digibeectl session in
 * DigibeeCredentials goes raw, and guessing wrong answers a 401 that reads like
 * an expired login.
 *
 * Nothing here prints the credential: `expiresAt()` is the only thing about it
 * worth showing anybody.
 */
final class MonitorSession
{
    public function __construct(
        private readonly string $authorization,
        private readonly string $apikey,
    ) {}

    public static function fromFile(string $path): ?self
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_string($data['authorization'] ?? null) || ! is_string($data['apikey'] ?? null)) {
            return null;
        }

        return new self($data['authorization'], $data['apikey']);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return ['authorization' => $this->authorization, 'apikey' => $this->apikey];
    }

    /**
     * Read from the JWT's own `exp` without verifying the signature: this only
     * decides whether to ask for a new login before calling, and the platform
     * still makes the real decision.
     */
    public function expiresAt(): ?CarbonImmutable
    {
        $exp = $this->claim('exp');

        return is_int($exp) ? CarbonImmutable::createFromTimestamp($exp) : null;
    }

    /** The realm the token was issued for — the same unverified read as `expiresAt()`. */
    public function realm(): ?string
    {
        $realm = $this->claim('realm');

        return is_string($realm) ? $realm : null;
    }

    private function claim(string $name): mixed
    {
        $parts = explode('.', preg_replace('/^Bearer\s+/i', '', $this->authorization));

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($payload) ? ($payload[$name] ?? null) : null;
    }

    /** A minute of margin, so a session never expires halfway through a run. */
    public function expired(): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && $expiresAt->subMinute()->isPast();
    }
}
