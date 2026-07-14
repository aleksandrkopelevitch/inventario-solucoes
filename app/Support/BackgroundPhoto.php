<?php

namespace App\Support;

use App\Enums\BackgroundTheme;

class BackgroundPhoto
{
    /**
     * Curated Unsplash photos for dashboard backgrounds.
     * All photo_ids verified to return HTTP 200 from Unsplash CDN.
     * Each entry: id (local slug), photo_id (Unsplash ID), label.
     */
    public static function all(): array
    {
        return [
            // Natureza
            ['id' => 'mountains',      'photo_id' => '1464822759023-fed622ff2c3b', 'label' => 'Montanhas'],
            ['id' => 'forest',         'photo_id' => '1513836279014-a89f7a76ae86', 'label' => 'Floresta'],
            ['id' => 'ocean',          'photo_id' => '1505118380757-91f5f5632de0', 'label' => 'Oceano'],
            ['id' => 'desert',         'photo_id' => '1509316785289-025f5b846b35', 'label' => 'Deserto'],
            ['id' => 'aurora',         'photo_id' => '1531366936337-7c912a4589a7', 'label' => 'Aurora Boreal'],
            ['id' => 'lavender',       'photo_id' => '1499002238440-d264edd596ec', 'label' => 'Lavanda'],
            ['id' => 'misty',          'photo_id' => '1506905925346-21bda4d32df4', 'label' => 'Neblina'],
            ['id' => 'night-sky',      'photo_id' => '1419242902214-272b3f66ee7a', 'label' => 'Céu Noturno'],
            ['id' => 'canyon',         'photo_id' => '1474044159687-1ee9f3a51722', 'label' => 'Cânion'],
            ['id' => 'lake',           'photo_id' => '1507525428034-b723cf961d3e', 'label' => 'Praia'],

            // Cozy / Trabalho
            ['id' => 'coffee-desk',    'photo_id' => '1495474472287-4d71bcdd2085', 'label' => 'Café & Trabalho'],
            ['id' => 'office-plants',  'photo_id' => '1524758631624-e2822e304c36', 'label' => 'Escritório Verde'],
            ['id' => 'rainy-window',   'photo_id' => '1441974231531-c6227db76b6e', 'label' => 'Janela com Chuva'],
            ['id' => 'cozy-books',     'photo_id' => '1526170375885-4d8ecf77b99f', 'label' => 'Leitura Cozy'],
            ['id' => 'laptop-coffee',  'photo_id' => '1531297484001-80022131f5a1', 'label' => 'Laptop & Café'],
            ['id' => 'minimal-desk',   'photo_id' => '1542626991-cbc4e32524cc',    'label' => 'Mesa Minimal'],
            ['id' => 'window-plants',  'photo_id' => '1493246507139-91e8fad9978e', 'label' => 'Plantas na Janela'],
            ['id' => 'cozy-cafe',      'photo_id' => '1501339847302-ac426a4a7cbb', 'label' => 'Cafeteria'],
            ['id' => 'home-office',    'photo_id' => '1521017432531-fbd92d768814', 'label' => 'Home Office'],
            ['id' => 'morning-desk',   'photo_id' => '1497366216548-37526070297c', 'label' => 'Manhã no Escritório'],
        ];
    }

    public static function thumbUrl(string $photoId): string
    {
        return "https://images.unsplash.com/photo-{$photoId}?w=300&h=200&fit=crop&auto=format&q=70";
    }

    public static function fullUrl(string $photoId): string
    {
        return "https://images.unsplash.com/photo-{$photoId}?w=1920&h=1080&fit=crop&auto=format&q=85";
    }

    public static function cssBackground(string $photoId): string
    {
        return "linear-gradient(rgba(0,0,0,0.05), rgba(0,0,0,0.20)), url('" . self::fullUrl($photoId) . "') center/cover no-repeat fixed";
    }

    public static function cssFromPreference(string $type, string $value): string
    {
        if ($type === 'gradient') {
            $theme = BackgroundTheme::tryFrom($value);

            return $theme ? $theme->gradient() : BackgroundTheme::default()->gradient();
        }

        return self::cssBackground($value);
    }
}
