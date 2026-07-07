<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RiderMapifyRoutesTest extends TestCase
{
    public function test_rider_mapify_routes_are_registered_with_rider_auth(): void
    {
        foreach ([
            'api/rider/mapify-search',
            'api/rider/mapify-geocoding',
            'api/rider/mapify-reverse-geocoding',
            'api/rider/mapify-tiles/{theme}',
            'api/rider/mapify-tiles/{theme}/{z}/{x}/{y}',
        ] as $uri) {
            $route = collect(Route::getRoutes())->first(
                fn ($route) => $route->uri() === $uri && in_array('GET', $route->methods(), true)
            );

            $this->assertNotNull($route, "{$uri} route is missing");
            $this->assertContains('tenant.db', $route->gatherMiddleware());
            $this->assertContains('auth.rider.jwt', $route->gatherMiddleware());
        }
    }
}
