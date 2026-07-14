<?php

namespace App\Enums;

enum SyncMode: string
{
    case Synchronous = 'synchronous';
    case Asynchronous = 'asynchronous';
    case Batch = 'batch';

    public function label(): string
    {
        return match ($this) {
            self::Synchronous  => 'Síncrono',
            self::Asynchronous => 'Assíncrono',
            self::Batch        => 'Batch',
        };
    }
}
