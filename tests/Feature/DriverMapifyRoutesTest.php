<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DriverMapifyRoutesTest extends TestCase
{
    public function test_driver_mapify_tile_routes_are_registered_with_driver_auth(): void
    {
        foreach ([
            'api/driver/mapify-tiles/{theme}',
            'api/driver/mapify-tiles/{theme}/{z}/{x}/{y}',
        ] as $uri) {
            $route = collect(Route::getRoutes())->first(
                fn ($route) => $route->uri() === $uri && in_array('GET', $route->methods(), true)
            );

            $this->assertNotNull($route, "{$uri} route is missing");
            $this->assertContains('tenant.db', $route->gatherMiddleware());
            $this->assertContains('auth.driver.jwt', $route->gatherMiddleware());
        }
    }
}
