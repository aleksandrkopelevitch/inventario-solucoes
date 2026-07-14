<?php

namespace App\Enums;

enum BackgroundTheme: string
{
    case Nevoa = 'nevoa';
    case Ardosia = 'ardosia';
    case Obsidiana = 'obsidiana';
    case Chama = 'chama';
    case Rosa = 'rosa';
    case Lavanda = 'lavanda';
    case Violeta = 'violeta';
    case Oceano = 'oceano';
    case Hortela = 'hortela';

    public static function default(): self
    {
        return self::Oceano;
    }

    public function gradient(): string
    {
        return match ($this) {
            self::Nevoa     => 'linear-gradient(-45deg, #a3becc, #7a8f99 100%)',
            self::Ardosia   => 'linear-gradient(-45deg, #7a8f99, #1f1e30 100%)',
            self::Obsidiana => 'linear-gradient(-45deg, #1f1e30, #1a0900 100%)',
            self::Chama     => 'linear-gradient(-45deg, #f9791d, #e53939 100%)',
            self::Rosa      => 'linear-gradient(-45deg, #ff66cc, #e62e4d 100%)',
            self::Lavanda   => 'linear-gradient(-45deg, #e65ce6, #7a52cc 100%)',
            self::Violeta   => 'linear-gradient(-45deg, #a17ee6, #1891ff 100%)',
            self::Oceano    => 'linear-gradient(-45deg, #1891ff, #24f2e1 100%)',
            self::Hortela   => 'linear-gradient(-45deg, #55f289, #00bfe6 100%)',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nevoa     => 'Névoa',
            self::Ardosia   => 'Ardósia',
            self::Obsidiana => 'Obsidiana',
            self::Chama     => 'Chama',
            self::Rosa      => 'Rosa',
            self::Lavanda   => 'Lavanda',
            self::Violeta   => 'Violeta',
            self::Oceano    => 'Oceano',
            self::Hortela   => 'Hortelã',
        };
    }
}
