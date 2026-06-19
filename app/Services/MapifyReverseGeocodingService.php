<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class MapifyReverseGeocodingService
{
    public function isAvailable(): bool
    {
        return filled(config('services.mapify.api_token'));
    }

    public function looksLikeCoordinates(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        return (bool) preg_match(
            '/^-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?$/',
            trim($value)
        );
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    public function extractCoordinates(mixed $point): ?array
    {
        if (is_array($point)) {
            $lat = $point['latitude'] ?? $point['lat'] ?? null;
            $lon = $point['longitude'] ?? $point['lng'] ?? $point['lon'] ?? null;
        } elseif (is_string($point)) {
            $decoded = json_decode($point, true);
            if (is_array($decoded)) {
                return $this->extractCoordinates($decoded);
            }

            if (!str_contains($point, ',')) {
                return null;
            }

            [$lat, $lon] = array_map('trim', explode(',', $point, 2));
        } else {
            return null;
        }

        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        $lat = (float) $lat;
        $lon = (float) $lon;

        if ($lat === 0.0 && $lon === 0.0) {
            return null;
        }

        return ['lat' => $lat, 'lon' => $lon];
    }

    public function extractLabelFromResponse(array $data): ?string
    {
        $results = $data['results'] ?? ($data['data']['results'] ?? null);
        if (!is_array($results) || $results === []) {
            return null;
        }

        $first = $results[0];

        return $first['label'] ?? $first['name'] ?? null;
    }

    public function reverseGeocode(float $lat, float $lon, int $size = 1): ?string
    {
        $cacheKey = sprintf('mapify_reverse_geocode:%s:%s:%d', $lat, $lon, $size);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $payload = $this->fetchReverseGeocoding($lat, $lon, $size);
        $label = $payload ? $this->extractLabelFromResponse($payload) : null;

        if (filled($label)) {
            Cache::put($cacheKey, $label, now()->addDay());
        }

        return $label;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchReverseGeocoding(float $lat, float $lon, int $size = 1): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $token = (string) config('services.mapify.api_token');
        $baseUrl = rtrim((string) config('services.mapify.base_url'), '/');

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->get($baseUrl . '/api/v1/proxy/reverse_geocoding', [
                'lat' => $lat,
                'lon' => $lon,
                'size' => $size,
            ]);

        if ($response->failed()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    public function resolveDisplayName(?string $location, mixed $point): ?string
    {
        if (!$this->isAvailable()) {
            return $location;
        }

        $coordinates = $this->extractCoordinates($point);
        if ($coordinates === null) {
            return $location;
        }

        $shouldResolve = $location === null
            || trim((string) $location) === ''
            || $this->looksLikeCoordinates($location);

        if (!$shouldResolve) {
            return $location;
        }

        $resolved = $this->reverseGeocode($coordinates['lat'], $coordinates['lon']);

        return filled($resolved) ? $resolved : $location;
    }
}
