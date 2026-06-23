<?php

namespace App\Services;

use Illuminate\Http\Request;

class MapifyNearbySearchService
{
    public function radiusKm(): float
    {
        return (float) config('services.mapify.nearby_radius_km', 50);
    }

    public function fetchSize(): int
    {
        return (int) config('services.mapify.nearby_fetch_size', 50);
    }

    public function resolveResponseLimit(Request $request): int
    {
        $default = (int) config('services.mapify.nearby_default_size', 20);
        $minimum = (int) config('services.mapify.nearby_min_size', 20);
        $maximum = (int) config('services.mapify.nearby_max_size', 50);

        $requested = $request->filled('size') ? (int) $request->input('size') : $default;

        return min(max($requested, $minimum), $maximum);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function filterPayload(array $payload, float $lat, float $lon, int $limit): array
    {
        $radiusKm = $this->radiusKm();

        foreach (['results', 'features'] as $collectionKey) {
            if (!isset($payload[$collectionKey]) || !is_array($payload[$collectionKey])) {
                continue;
            }

            $filtered = $this->filterItems($payload[$collectionKey], $lat, $lon, $radiusKm, $limit);
            $payload[$collectionKey] = $filtered;

            return $payload;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload['data'] = $this->filterPayload($payload['data'], $lat, $lon, $limit);

            return $payload;
        }

        return $payload;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function filterItems(array $items, float $lat, float $lon, float $radiusKm, int $limit): array
    {
        $ranked = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $coordinates = $this->extractCoordinates($item);
            if ($coordinates === null) {
                continue;
            }

            $distanceKm = $this->distanceKm(
                $lat,
                $lon,
                $coordinates['lat'],
                $coordinates['lon']
            );

            if ($distanceKm > $radiusKm) {
                continue;
            }

            $ranked[] = [
                'index' => $index,
                'item' => $item,
                'distance_km' => $distanceKm,
            ];
        }

        usort($ranked, static fn (array $left, array $right): int => $left['distance_km'] <=> $right['distance_km']);

        return array_values(array_map(
            static fn (array $entry): array => $entry['item'],
            array_slice($ranked, 0, $limit)
        ));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{lat: float, lon: float}|null
     */
    private function extractCoordinates(array $item): ?array
    {
        $lat = $item['lat'] ?? $item['latitude'] ?? null;
        $lon = $item['lon'] ?? $item['lng'] ?? $item['longitude'] ?? null;

        if (is_numeric($lat) && is_numeric($lon)) {
            return ['lat' => (float) $lat, 'lon' => (float) $lon];
        }

        $geometry = $item['geometry'] ?? null;
        if (!is_array($geometry)) {
            return null;
        }

        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        $lon = $coordinates[0];
        $lat = $coordinates[1];

        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return ['lat' => (float) $lat, 'lon' => (float) $lon];
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
