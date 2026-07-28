<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AppMaintenanceSetting;
use App\Models\CompanySetting;

class CheckAppAvailibility
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $setting = CompanySetting::orderBy("id", "DESC")->first();

        if (auth("driver")->check()) {
            $globalStatus = AppMaintenanceSetting::current()->statusFor('driver');
            if ($globalStatus['maintenance']) {
                return response()->json([
                    'error' => 1,
                    'maintenance' => true,
                    'message' => $globalStatus['message'],
                ], 503);
            }

            $driver = auth("driver")->user();
            $status = strtolower((string) ($driver->status ?? 'pending'));
            $approvedStatuses = ['accepted', 'approved', 'active'];

            if (!in_array($status, $approvedStatuses, true)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver is not approved by Company Admin'
                ], 400);
            }

            if ($setting && $setting->driver_app == "disable") {
                return response()->json([
                    'error' => 1,
                    'maintenance' => true,
                    'message' => AppMaintenanceSetting::DEFAULT_MESSAGE
                ], 503);
            }
        }

        if (auth("rider")->check()) {
            $globalStatus = AppMaintenanceSetting::current()->statusFor('customer');
            if ($globalStatus['maintenance']) {
                return response()->json([
                    'error' => 1,
                    'maintenance' => true,
                    'message' => $globalStatus['message'],
                ], 503);
            }

            if ($setting && $setting->customer_app == "disable") {
                return response()->json([
                    'error' => 1,
                    'maintenance' => true,
                    'message' => AppMaintenanceSetting::DEFAULT_MESSAGE
                ], 503);
            }
        }
        return $next($request);
    }
}
