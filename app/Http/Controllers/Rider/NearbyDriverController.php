<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\CompanyDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NearbyDriverController extends Controller
{
    private const DEFAULT_RADIUS_KM = 2.0;
    private const MAX_RADIUS_KM = 5.0;
    private const RESULT_LIMIT = 100;
    private const ACTIVE_BOOKING_STATUSES = [
        'pending_acceptance',
        'arrived',
        'started',
        'ongoing',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:' . self::MAX_RADIUS_KM],
            'vehicle_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $latitude = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];
        $radiusKm = (float) ($validated['radius'] ?? self::DEFAULT_RADIUS_KM);

        if ($latitude === 0.0 && $longitude === 0.0) {
            return response()->json([
                'message' => 'The provided location is invalid.',
                'errors' => [
                    'latitude' => ['Latitude and longitude cannot both be zero.'],
                ],
            ], 422);
        }

        // Limit the candidate set before applying the more expensive Haversine calculation.
        $latitudeDelta = $radiusKm / 111.045;
        $longitudeScale = max(abs(cos(deg2rad($latitude))), 0.01);
        $longitudeDelta = $radiusKm / (111.045 * $longitudeScale);

        $distanceSql = '(6371 * ACOS(LEAST(1, GREATEST(-1, '
            . 'COS(RADIANS(?)) * COS(RADIANS(latitude)) '
            . '* COS(RADIANS(longitude) - RADIANS(?)) '
            . '+ SIN(RADIANS(?)) * SIN(RADIANS(latitude))'
            . '))))';

        $drivers = CompanyDriver::query()
            ->select([
                'drivers.id',
                'drivers.latitude',
                'drivers.longitude',
                'drivers.assigned_vehicle',
            ])
            ->selectRaw($distanceSql . ' AS distance_km', [
                $latitude,
                $longitude,
                $latitude,
            ])
            ->where('drivers.online_status', 'online')
            ->where('drivers.driving_status', 'idle')
            ->whereNotNull('drivers.latitude')
            ->whereNotNull('drivers.longitude')
            ->whereBetween('drivers.latitude', [
                $latitude - $latitudeDelta,
                $latitude + $latitudeDelta,
            ])
            ->whereBetween('drivers.longitude', [
                $longitude - $longitudeDelta,
                $longitude + $longitudeDelta,
            ])
            ->when(
                isset($validated['vehicle_id']),
                fn (Builder $query) => $query->where(
                    'drivers.assigned_vehicle',
                    $validated['vehicle_id']
                )
            )
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('bookings')
                    ->where(function ($bookingQuery) {
                        $bookingQuery
                            ->whereColumn('bookings.driver', 'drivers.id')
                            ->orWhereColumn('bookings.pending_driver_id', 'drivers.id');
                    })
                    ->whereIn('bookings.booking_status', self::ACTIVE_BOOKING_STATUSES);
            })
            ->havingRaw('distance_km <= ?', [$radiusKm])
            ->orderBy('distance_km')
            ->limit(self::RESULT_LIMIT)
            ->get()
            ->map(static fn (CompanyDriver $driver): array => [
                'driver_id' => (int) $driver->id,
                'latitude' => (float) $driver->latitude,
                'longitude' => (float) $driver->longitude,
                'vehicle_id' => $driver->assigned_vehicle !== null
                    ? (int) $driver->assigned_vehicle
                    : null,
                'distance_km' => round((float) $driver->distance_km, 3),
            ])
            ->values();

        return response()->json([
            'success' => 1,
            'radius_km' => $radiusKm,
            'count' => $drivers->count(),
            'drivers' => $drivers,
        ]);
    }
}
