<?php

namespace App\Enums;

enum PersonSolutionRole: string
{
    case Technical = 'technical';
    case Business = 'business';
    case Manager = 'manager';
    case Support = 'support';
    case KeyUser = 'key_user';
    case VendorContact = 'vendor_contact';

    public function label(): string
    {
        return match ($this) {
            self::Technical     => 'Owner técnico',
            self::Business      => 'Owner de negócio',
            self::Manager       => 'Gestor',
            self::Support       => 'Suporte',
            self::KeyUser       => 'Key user',
            self::VendorContact => 'Contato do fornecedor',
        };
    }
}
