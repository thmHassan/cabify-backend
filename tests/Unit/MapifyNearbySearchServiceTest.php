<?php

namespace Tests\Unit;

use App\Services\MapifyNearbySearchService;
use Illuminate\Http\Request;
use Tests\TestCase;

class MapifyNearbySearchServiceTest extends TestCase
{
    private MapifyNearbySearchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MapifyNearbySearchService();
    }

    public function test_resolve_response_limit_uses_minimum_for_nearby_searches(): void
    {
        $request = Request::create('/company/mapify-search', 'GET', [
            'size' => 8,
        ]);

        $this->assertSame(20, $this->service->resolveResponseLimit($request));
    }

    public function test_filter_payload_keeps_results_within_radius_sorted_by_distance(): void
    {
        $payload = [
            'results' => [
                [
                    'name' => 'Far KFC',
                    'lat' => 34.50,
                    'lon' => 73.21,
                ],
                [
                    'name' => 'Near KFC',
                    'lat' => 33.61,
                    'lon' => 73.21,
                ],
                [
                    'name' => 'Mid KFC',
                    'lat' => 33.90,
                    'lon' => 73.21,
                ],
            ],
        ];

        $filtered = $this->service->filterPayload($payload, 33.600287875, 73.21348275, 2);

        $this->assertCount(2, $filtered['results']);
        $this->assertSame('Near KFC', $filtered['results'][0]['name']);
        $this->assertSame('Mid KFC', $filtered['results'][1]['name']);
    }

    public function test_filter_payload_supports_geojson_features(): void
    {
        $payload = [
            'features' => [
                [
                    'properties' => ['name' => 'Outside'],
                    'geometry' => [
                        'coordinates' => [74.35, 31.52],
                    ],
                ],
                [
                    'properties' => ['name' => 'Inside'],
                    'geometry' => [
                        'coordinates' => [73.21, 33.61],
                    ],
                ],
            ],
        ];

        $filtered = $this->service->filterPayload($payload, 33.600287875, 73.21348275, 10);

        $this->assertCount(1, $filtered['features']);
        $this->assertSame('Inside', $filtered['features'][0]['properties']['name']);
    }
}
