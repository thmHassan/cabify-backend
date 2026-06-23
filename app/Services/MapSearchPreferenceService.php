<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Dispatcher;

class MapSearchPreferenceService
{
    /**
     * @return array{nearby_search_enabled: bool, search_boundary_country: ?string}
     */
    public function resolve(): array
    {
        $dispatcher = auth('dispatcher')->user();
        if ($dispatcher instanceof Dispatcher) {
            return [
                'nearby_search_enabled' => (bool) $dispatcher->nearby_search_enabled,
                'search_boundary_country' => $dispatcher->search_boundary_country,
            ];
        }

        $settings = CompanySetting::orderBy('id', 'DESC')->first();
        if ($settings) {
            return [
                'nearby_search_enabled' => (bool) $settings->nearby_search_enabled,
                'search_boundary_country' => $settings->search_boundary_country,
            ];
        }

        return [
            'nearby_search_enabled' => false,
            'search_boundary_country' => null,
        ];
    }

    public function save(bool $nearbySearchEnabled, ?string $boundaryCountry): void
    {
        $normalizedCountry = filled($boundaryCountry)
            ? strtoupper(trim($boundaryCountry))
            : null;

        $dispatcher = auth('dispatcher')->user();
        if ($dispatcher instanceof Dispatcher) {
            $dispatcher->nearby_search_enabled = $nearbySearchEnabled;
            $dispatcher->search_boundary_country = $normalizedCountry;
            $dispatcher->save();

            return;
        }

        $settings = CompanySetting::orderBy('id', 'DESC')->first();
        if (!$settings) {
            return;
        }

        $settings->nearby_search_enabled = $nearbySearchEnabled;
        $settings->search_boundary_country = $normalizedCountry;
        $settings->save();
    }
}
