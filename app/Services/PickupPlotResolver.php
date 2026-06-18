<?php

namespace App\Services;

use App\Models\CompanyPlot;

class PickupPlotResolver
{
    public function resolveFromPickupPoint(?string $pickupPoint): ?int
    {
        if (!$pickupPoint || !str_contains($pickupPoint, ',')) {
            return null;
        }

        [$latStr, $lngStr] = explode(',', $pickupPoint, 2);
        $lat = (float) trim($latStr);
        $lng = (float) trim($lngStr);

        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        foreach (CompanyPlot::orderByDesc('id')->get() as $plot) {
            $polygon = $this->parsePolygon($plot->features);
            if ($polygon && $this->pointInPolygon($lat, $lng, $polygon)) {
                return (int) $plot->id;
            }
        }

        return null;
    }

    private function parsePolygon(mixed $features): ?array
    {
        if (!$features) {
            return null;
        }

        $decoded = is_string($features) ? json_decode($features, true) : $features;
        if (!is_array($decoded)) {
            return null;
        }

        $geometry = $decoded['geometry'] ?? $decoded;
        $coordinates = $geometry['coordinates'] ?? null;

        if (is_string($coordinates)) {
            $coordinates = json_decode($coordinates, true);
        }

        if (!is_array($coordinates)) {
            return null;
        }

        return is_array($coordinates[0][0] ?? null) ? $coordinates[0] : $coordinates;
    }

    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        if (count($polygon) === 2 && is_array($polygon[0]) && is_array($polygon[1])) {
            $lng1 = $polygon[0][0];
            $lat1 = $polygon[0][1];
            $lng2 = $polygon[1][0];
            $lat2 = $polygon[1][1];

            return $lat >= min($lat1, $lat2)
                && $lat <= max($lat1, $lat2)
                && $lng >= min($lng1, $lng2)
                && $lng <= max($lng1, $lng2);
        }

        $inside = false;
        $x = $lng;
        $y = $lat;
        $numPoints = count($polygon);

        for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
