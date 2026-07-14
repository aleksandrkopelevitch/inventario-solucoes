<?php

namespace App\Enums;

enum CompanyKind: string
{
    case Internal = 'internal';
    case Vendor = 'vendor';
    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Interno',
            self::Vendor   => 'Fornecedor',
            self::Partner  => 'Parceiro',
        };
    }
}
