<?php

namespace App\Enums;

enum ContactType: string
{
    case Email = 'email';
    case Phone = 'phone';
    case Whatsapp = 'whatsapp';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Email    => 'E-mail',
            self::Phone    => 'Telefone',
            self::Whatsapp => 'WhatsApp',
            self::Other    => 'Outro',
        };
    }
}
