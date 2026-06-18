<?php

namespace App\Support;

class MapsApi
{
    public const GOOGLE = 'google';

    public const MAPIFY = 'mapify';

    /** @deprecated Accept during migration; normalized to {@see MAPIFY} on save/read */
    public const LEGACY_BARIKOI = 'barikoi';

    public static function allowedInputValues(): array
    {
        return [self::GOOGLE, self::MAPIFY, self::LEGACY_BARIKOI];
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === self::LEGACY_BARIKOI) {
            return self::MAPIFY;
        }

        return $value;
    }

    public static function isMapify(?string $value): bool
    {
        return in_array($value, [self::MAPIFY, self::LEGACY_BARIKOI], true);
    }
}
