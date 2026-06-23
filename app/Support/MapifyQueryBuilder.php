<?php

namespace App\Support;

use Illuminate\Http\Request;

class MapifyQueryBuilder
{
    public static function parseNearbySearch(Request $request): bool
    {
        if (!$request->has('nearby_search')) {
            return false;
        }

        $value = $request->input('nearby_search');

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    public static function normalizeBoundaryCountry(?string $value): ?string
    {
        if (!filled($value)) {
            return null;
        }

        return strtoupper(trim($value));
    }

    public static function resolveBoundaryCountry(
        Request $request,
        bool $nearbySearch,
        ?string $resolvedUserCountry = null
    ): ?string {
        if ($request->filled('boundary_country')) {
            return self::normalizeBoundaryCountry($request->input('boundary_country'));
        }

        if ($nearbySearch) {
            return self::normalizeBoundaryCountry($resolvedUserCountry);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchQuery(
        Request $request,
        bool $nearbySearch,
        ?string $resolvedUserCountry = null
    ): array {
        $query = [
            'q' => $request->input('q'),
        ];

        if ($request->filled('lat')) {
            $query['lat'] = $request->input('lat');
        }

        if ($request->filled('lon')) {
            $query['lon'] = $request->input('lon');
        }

        if ($request->filled('size') && !$nearbySearch) {
            $query['size'] = $request->input('size');
        }

        if ($nearbySearch) {
            $query['size'] = (int) config('services.mapify.nearby_fetch_size', 50);
        }

        $boundaryCountry = self::resolveBoundaryCountry(
            $request,
            $nearbySearch,
            $resolvedUserCountry
        );

        if (filled($boundaryCountry)) {
            $query['boundary_country'] = $boundaryCountry;
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildGeocodingQuery(
        Request $request,
        bool $nearbySearch,
        ?string $resolvedUserCountry = null
    ): array {
        $query = [
            'q' => $request->input('q'),
        ];

        if ($request->filled('lat')) {
            $query['lat'] = $request->input('lat');
        }

        if ($request->filled('lon')) {
            $query['lon'] = $request->input('lon');
        }

        $boundaryCountry = self::resolveBoundaryCountry(
            $request,
            $nearbySearch,
            $resolvedUserCountry
        );

        if (filled($boundaryCountry)) {
            $query['boundary_country'] = $boundaryCountry;
        }

        return $query;
    }
}
