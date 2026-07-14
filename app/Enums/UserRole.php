<?php

namespace App\Enums;

enum UserRole: string
{
    case Viewer = 'viewer';
    case Agent = 'agent';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Visualizador',
            self::Agent  => 'Agente',
            self::Admin  => 'Administrador',
        };
    }
}
