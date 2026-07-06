<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DriverOnboardingRouteGuardTest extends TestCase
{
    /**
     * @dataProvider pendingSafeDriverRoutes
     */
    public function test_pending_safe_driver_routes_do_not_use_approval_guard(string $method, string $uri): void
    {
        $middleware = $this->middlewareFor($method, $uri);

        $this->assertContains('auth.driver.jwt', $middleware);
        $this->assertNotContains('check.app.availibility', $middleware);
    }

    /**
     * @dataProvider publicDriverSetupRoutes
     */
    public function test_public_driver_setup_routes_do_not_use_approval_guard(string $method, string $uri): void
    {
        $middleware = $this->middlewareFor($method, $uri);

        $this->assertNotContains('auth.driver.jwt', $middleware);
        $this->assertNotContains('check.app.availibility', $middleware);
    }

    /**
     * @dataProvider approvedOnlyDriverRoutes
     */
    public function test_operational_driver_routes_keep_approval_guard(string $method, string $uri): void
    {
        $middleware = $this->middlewareFor($method, $uri);

        $this->assertContains('auth.driver.jwt', $middleware);
        $this->assertContains('check.app.availibility', $middleware);
    }

    public static function pendingSafeDriverRoutes(): array
    {
        return [
            ['GET', 'api/driver/get-profile'],
            ['POST', 'api/driver/update-profile'],
            ['POST', 'api/driver/store-token'],
            ['GET', 'api/driver/document-list'],
            ['POST', 'api/driver/document-upload'],
            ['GET', 'api/driver/vehicle-information'],
            ['POST', 'api/driver/vehicle-information'],
            ['POST', 'api/driver/change-vehicle-information'],
            ['GET', 'api/driver/get-mobile-setting'],
            ['GET', 'api/driver/get-api-keys'],
        ];
    }

    public static function publicDriverSetupRoutes(): array
    {
        return [
            ['POST', 'api/driver/login'],
            ['POST', 'api/driver/register'],
            ['POST', 'api/driver/resend-otp'],
            ['POST', 'api/driver/verify-otp'],
            ['POST', 'api/driver/verify-password'],
            ['GET', 'api/driver/document-requirements'],
            ['GET', 'api/driver/vehicle-type-list'],
        ];
    }

    public static function approvedOnlyDriverRoutes(): array
    {
        return [
            ['GET', 'api/driver/change-status'],
            ['GET', 'api/driver/current-ride'],
            ['POST', 'api/driver/accept-ride'],
            ['POST', 'api/driver/reject-ride'],
            ['GET', 'api/driver/list-ride-for-bidding'],
            ['GET', 'api/driver/driver-ranking'],
            ['GET', 'api/driver/list-plot'],
            ['POST', 'api/driver/purchase-package'],
        ];
    }

    private function middlewareFor(string $method, string $uri): array
    {
        $request = Request::create($uri, $method);
        $route = Route::getRoutes()->match($request);

        return $route->gatherMiddleware();
    }
}
