<?php

namespace App\Enums;

enum IntegrationStatus: string
{
    case Active = 'active';
    case InDevelopment = 'in_development';
    case Planned = 'planned';
    case Deprecated = 'deprecated';

    public function label(): string
    {
        return match ($this) {
            self::Active        => 'Ativa',
            self::InDevelopment => 'Em desenvolvimento',
            self::Planned       => 'Planejada',
            self::Deprecated    => 'Descontinuada',
        };
    }
}
