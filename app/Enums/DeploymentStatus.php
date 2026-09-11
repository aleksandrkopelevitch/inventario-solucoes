<?php

namespace App\Enums;

/**
 * What the platform says about a deployment, as observed across the 111 live
 * ones in `test`: `SERVICE_ACTIVE` 108, `SERVICE_ERROR` 2, `DELETING` 1.
 *
 * `Unknown` is not defensive padding. These strings are undocumented — the
 * whole runtime API is — so a status nobody has seen yet must read as "not
 * terminal, not healthy" rather than crash a correction loop mid-deploy or,
 * worse, be mistaken for success.
 */
enum DeploymentStatus: string
{
    case Active = 'SERVICE_ACTIVE';
    case Error = 'SERVICE_ERROR';
    case Deleting = 'DELETING';
    case Starting = 'STARTING';
    case Unknown = 'UNKNOWN';

    public static function fromPlatform(?string $status): self
    {
        return self::tryFrom((string) $status) ?? self::Unknown;
    }

    /** Whether waiting any longer can change the answer. */
    public function settled(): bool
    {
        return in_array($this, [self::Active, self::Error], true);
    }

    public function healthy(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active   => 'ativo',
            self::Error    => 'com erro',
            self::Deleting => 'sendo removido',
            self::Starting => 'subindo',
            self::Unknown  => 'desconhecido',
        };
    }
}
