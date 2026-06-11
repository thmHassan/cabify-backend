<?php

namespace App\Providers;

use App\Mail\Transport\ZeptoMailTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('zeptomail', function (array $config) {
            return new ZeptoMailTransport(
                token: $config['token'] ?? '',
                endpoint: $config['endpoint'] ?? 'https://api.zeptomail.com/v1.1/email',
            );
        });
    }
}
