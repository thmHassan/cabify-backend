<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RiderNearbyDriversRouteTest extends TestCase
{
    public function test_nearby_drivers_route_is_rider_authenticated_and_tenant_scoped(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/rider/nearby-drivers');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $middleware = $route->gatherMiddleware();

        $this->assertContains('tenant.db', $middleware);
        $this->assertContains('auth.rider.jwt', $middleware);
        $this->assertContains('check.app.availibility', $middleware);
    }
}
