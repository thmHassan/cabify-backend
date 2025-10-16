<?php

namespace App\Providers;

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
        // JWTAuth::resolveGuard(function () {
        //     return auth('tenant');
        // });
    }
}
