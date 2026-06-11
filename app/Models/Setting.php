<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;
    protected $table = "settings";

    public static function googleMapKey(): ?string
    {
        $setting = static::orderBy('id', 'DESC')->first();

        return $setting?->google_map_key ?: config('services.google_maps.api_key');
    }

    public static function barikoiKey(): ?string
    {
        $setting = static::orderBy('id', 'DESC')->first();

        return $setting?->barikoi_key ?: config('services.barikoi.api_key');
    }

    public static function stripeSecret(): ?string
    {
        $setting = static::orderBy('id', 'DESC')->first();

        return $setting?->stripe_secret ?: config('services.stripe.secret');
    }

    public static function stripeKey(): ?string
    {
        $setting = static::orderBy('id', 'DESC')->first();

        return $setting?->stripe_key ?: config('services.stripe.key');
    }

    public static function stripeWebhookSecret(): ?string
    {
        $setting = static::orderBy('id', 'DESC')->first();

        return $setting?->stripe_webhook_secret ?: config('services.stripe.webhook_secret');
    }
}
