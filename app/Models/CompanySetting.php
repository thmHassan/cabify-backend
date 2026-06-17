<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    use HasFactory;

    public const DEFAULT_SEARCH_RADIUS_KM = 1;

    protected $table = "settings";

    public static function isNearestDriverDispatchEnabled(): bool
    {
        return CompanyDispatchSystem::where('dispatch_system', 'auto_dispatch_nearest_driver')
            ->where('status', 'enable')
            ->exists();
    }

    public static function resolveSearchRadiusKm(?self $settings = null): ?float
    {
        if (!static::isNearestDriverDispatchEnabled()) {
            return null;
        }

        $settings = $settings ?? static::orderBy('id', 'DESC')->first();
        $radius = $settings?->search_radius;

        if ($radius === null || $radius === '') {
            return static::DEFAULT_SEARCH_RADIUS_KM;
        }

        $parsed = (float) $radius;

        return $parsed >= 1 ? $parsed : static::DEFAULT_SEARCH_RADIUS_KM;
    }
}
