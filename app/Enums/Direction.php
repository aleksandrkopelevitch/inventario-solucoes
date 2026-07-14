<?php

namespace App\Enums;

enum Direction: string
{
    case Unidirectional = 'unidirectional';
    case Bidirectional = 'bidirectional';

    public function label(): string
    {
        return match ($this) {
            self::Unidirectional => 'Unidirecional',
            self::Bidirectional  => 'Bidirecional',
        };
    }
}
