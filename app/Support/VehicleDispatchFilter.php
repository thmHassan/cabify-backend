<?php

namespace App\Support;

use App\Models\CompanyBooking;
use App\Models\CompanyDriver;
use Illuminate\Database\Eloquent\Builder;

class VehicleDispatchFilter
{
    public static function bookingRequiresSpecificVehicle(CompanyBooking $booking): bool
    {
        return filled($booking->vehicle);
    }

    public static function requestedVehicleId(CompanyBooking $booking): ?string
    {
        return self::bookingRequiresSpecificVehicle($booking)
            ? (string) $booking->vehicle
            : null;
    }

    public static function driverMatchesBooking(CompanyDriver $driver, CompanyBooking $booking): bool
    {
        if (!self::bookingRequiresSpecificVehicle($booking)) {
            return true;
        }

        return filled($driver->assigned_vehicle)
            && (string) $driver->assigned_vehicle === self::requestedVehicleId($booking);
    }

    public static function scopeDriversForBooking(Builder $query, CompanyBooking $booking, string $table = 'drivers'): Builder
    {
        if (!self::bookingRequiresSpecificVehicle($booking)) {
            return $query;
        }

        return $query->where("{$table}.assigned_vehicle", self::requestedVehicleId($booking));
    }

    public static function normalizeRequestedVehicle($request): ?string
    {
        $requestForVehicle = strtolower((string) ($request->input('request_for_vehicle') ?? ''));
        $vehicle = $request->input('vehicle');

        if ($requestForVehicle !== 'yes' || !filled($vehicle)) {
            return null;
        }

        return (string) $vehicle;
    }
}
