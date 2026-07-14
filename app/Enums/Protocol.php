<?php

namespace App\Enums;

enum Protocol: string
{
    case Rest = 'rest';
    case Soap = 'soap';
    case Sftp = 'sftp';
    case Rfc = 'rfc';
    case File = 'file';
    case Webhook = 'webhook';
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Rest    => 'REST',
            self::Soap    => 'SOAP',
            self::Sftp    => 'SFTP',
            self::Rfc     => 'RFC',
            self::File    => 'Arquivo',
            self::Webhook => 'Webhook',
            self::Event   => 'Evento',
        };
    }
}
