<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AppMaintenanceSetting;
use App\Services\SocketApiUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AppMaintenanceController extends Controller
{
    public function publicStatus(Request $request)
    {
        try {
            $request->validate([
                'app' => 'nullable|in:driver,customer,rider,driver_app,customer_app',
            ]);

            $setting = AppMaintenanceSetting::current();

            if ($request->filled('app')) {
                return response()->json([
                    'success' => 1,
                    'data' => $setting->statusFor((string) $request->app),
                ]);
            }

            return response()->json([
                'success' => 1,
                'data' => [
                    'driver' => $setting->statusFor('driver'),
                    'customer' => $setting->statusFor('customer'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show()
    {
        try {
            $setting = AppMaintenanceSetting::current();

            return response()->json([
                'success' => 1,
                'setting' => [
                    'driver_app' => $setting->driver_app,
                    'customer_app' => $setting->customer_app,
                    'message' => $setting->message ?: AppMaintenanceSetting::DEFAULT_MESSAGE,
                    'starts_at' => $setting->starts_at?->toISOString(),
                    'ends_at' => $setting->ends_at?->toISOString(),
                    'driver' => $setting->statusFor('driver'),
                    'customer' => $setting->statusFor('customer'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'driver_app' => 'nullable|in:enable,disable',
                'customer_app' => 'nullable|in:enable,disable',
                'message' => 'nullable|string|max:255',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after_or_equal:starts_at',
            ]);

            $setting = AppMaintenanceSetting::current();
            $setting->fill($validated);
            $setting->message = $setting->message ?: AppMaintenanceSetting::DEFAULT_MESSAGE;
            $setting->save();

            $this->notifyAppMaintenanceChanged($request, $setting);

            return response()->json([
                'success' => 1,
                'message' => 'App maintenance settings updated successfully',
                'setting' => [
                    'driver_app' => $setting->driver_app,
                    'customer_app' => $setting->customer_app,
                    'message' => $setting->message,
                    'starts_at' => $setting->starts_at?->toISOString(),
                    'ends_at' => $setting->ends_at?->toISOString(),
                    'driver' => $setting->statusFor('driver'),
                    'customer' => $setting->statusFor('customer'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function notifyAppMaintenanceChanged(Request $request, AppMaintenanceSetting $setting): void
    {
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
            ])->timeout(5)->post(
                SocketApiUrlResolver::endpoint($request, 'app-maintenance-changed'),
                [
                    'driver_app' => $setting->driver_app,
                    'customer_app' => $setting->customer_app,
                    'message' => $setting->message ?: AppMaintenanceSetting::DEFAULT_MESSAGE,
                    'starts_at' => $setting->starts_at?->toISOString(),
                    'ends_at' => $setting->ends_at?->toISOString(),
                    'driver' => $setting->statusFor('driver'),
                    'customer' => $setting->statusFor('customer'),
                    'changed_at' => now()->toISOString(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('App maintenance socket call failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
