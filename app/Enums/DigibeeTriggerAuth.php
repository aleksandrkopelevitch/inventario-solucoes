<?php

namespace App\Enums;

/**
 * How a web-protocol trigger authenticates its callers, as the three booleans
 * the stored `triggerSpec` carries.
 *
 * They are three separate keys rather than one mode field, so the flags are
 * emitted as a SET with exactly one true — a spec with both `basicAuth` and
 * `keyAuth` true is a question about precedence nobody has answered, and a
 * spec with all three false is an open endpoint.
 *
 * **`None` exists but is never a default.** Measured over the tenant's web
 * protocol triggers: `basicAuth: true` in 55 of the 56 `http` specs that carry
 * the key, `keyAuth: true` in 21 of the 28 `rest` ones. So the convention is
 * "authenticated", and `defaultFor()` follows it per kind. An unauthenticated
 * endpoint is a decision somebody makes out loud, not a value that arrives
 * because a caller left an argument out.
 */
enum DigibeeTriggerAuth: string
{
    case BasicAuth = 'basic';
    case KeyAuth = 'key';
    case Jwt = 'jwt';
    case None = 'none';

    /** What the tenant's own pipelines of that kind use. */
    public static function defaultFor(DigibeeTriggerKind $kind): self
    {
        return match ($kind) {
            DigibeeTriggerKind::Rest => self::KeyAuth,
            default                  => self::BasicAuth,
        };
    }

    /** @return array{basicAuth: bool, keyAuth: bool, jwt: bool} */
    public function flags(): array
    {
        return [
            'basicAuth' => $this === self::BasicAuth,
            'keyAuth'   => $this === self::KeyAuth,
            'jwt'       => $this === self::Jwt,
        ];
    }

    /**
     * Whether a caller needs a credential to reach the endpoint — which is
     * what makes the difference between a test case that can run and one the
     * matrix has to report as blocked.
     */
    public function requiresCredential(): bool
    {
        return $this !== self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::BasicAuth => 'Basic Auth',
            self::KeyAuth   => 'API Key',
            self::Jwt       => 'JWT',
            self::None      => 'sem autenticação',
        };
    }
}
