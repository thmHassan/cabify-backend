<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    use HasFactory;

    public const DEFAULT_SEARCH_RADIUS_KM = 1;
    public const DEFAULT_DISPATCH_TIMEOUT_SECONDS = 30;
    public const DEFAULT_RELEASE_LEAD_MINUTES = 60;
    public const DEFAULT_RELEASE_MODE = 'auto_then_bidding';
    public const RELEASE_MODES = [
        'auto_dispatch',
        'bidding',
        'auto_then_bidding',
        'manual_review',
    ];

    protected $table = "settings";

    protected $casts = [
        'auto_release_enabled' => 'boolean',
        'default_release_lead_minutes' => 'integer',
    ];

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

    public static function resolveDispatchTimeoutSeconds(?self $settings = null): int
    {
        $settings = $settings ?? static::orderBy('id', 'DESC')->first();
        $seconds = (int) ($settings?->dispatch_timeout ?? static::DEFAULT_DISPATCH_TIMEOUT_SECONDS);

        return $seconds > 0 ? $seconds : static::DEFAULT_DISPATCH_TIMEOUT_SECONDS;
    }

    public function autoReleaseEnabled(): bool
    {
        if ($this->auto_release_enabled === null) {
            return true;
        }

        return (bool) $this->auto_release_enabled;
    }

    public function defaultReleaseLeadMinutes(): int
    {
        $minutes = (int) ($this->default_release_lead_minutes ?? static::DEFAULT_RELEASE_LEAD_MINUTES);

        return max(0, min($minutes, 1440));
    }

    public function defaultReleaseMode(): string
    {
        $mode = (string) ($this->default_release_mode ?: static::DEFAULT_RELEASE_MODE);

        return in_array($mode, static::RELEASE_MODES, true) ? $mode : static::DEFAULT_RELEASE_MODE;
    }

    public static function resolveReleaseSettings(?self $settings = null): array
    {
        $settings = $settings ?? static::orderBy('id', 'DESC')->first();

        if (!$settings) {
            return [
                'enabled' => true,
                'lead_minutes' => static::DEFAULT_RELEASE_LEAD_MINUTES,
                'mode' => static::DEFAULT_RELEASE_MODE,
                'modes' => static::RELEASE_MODES,
            ];
        }

        return [
            'enabled' => $settings->autoReleaseEnabled(),
            'lead_minutes' => $settings->defaultReleaseLeadMinutes(),
            'mode' => $settings->defaultReleaseMode(),
            'modes' => static::RELEASE_MODES,
        ];
    }
}
