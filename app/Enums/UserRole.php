<?php

namespace App\Enums;

enum UserRole: string
{
    case Viewer = 'viewer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Visualizador',
            self::Admin  => 'Administrador',
        };
    }
}
