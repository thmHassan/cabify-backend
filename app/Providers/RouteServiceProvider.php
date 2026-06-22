<?php

namespace App\Providers;

use App\Support\TenantRequestContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $key = TenantRequestContext::rateLimitKey($request);
            $path = ltrim($request->path(), '/');

            if (str_starts_with($path, 'api/company/mapify-tiles/')) {
                return Limit::perMinute(2000)->by($key);
            }

            if (preg_match('#^api/company/(mapify-(search|geocoding|reverse-geocoding)|map-search-preferences)#', $path) === 1) {
                return Limit::perMinute(500)->by($key);
            }

            return Limit::perMinute(300)->by($key);
        });

        $this->routes(function () {
            // Public static pages — no session/cookie encryption (avoids APP_KEY issues)
            Route::view('/privacy-policy', 'privacy-policy')->name('privacy-policy');
            Route::view('/delete-account-info', 'delete-account-info')->name('delete-account-info');

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
