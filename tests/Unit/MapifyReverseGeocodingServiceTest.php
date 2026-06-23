<?php

namespace Tests\Unit;

use App\Services\MapifyReverseGeocodingService;
use Tests\TestCase;

class MapifyReverseGeocodingServiceTest extends TestCase
{
    private MapifyReverseGeocodingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MapifyReverseGeocodingService();
    }

    public function test_looks_like_coordinates_detects_lat_lon_pairs(): void
    {
        $this->assertTrue($this->service->looksLikeCoordinates('33.7297,73.0372'));
        $this->assertTrue($this->service->looksLikeCoordinates('33.7297, 73.0372'));
        $this->assertFalse($this->service->looksLikeCoordinates('Faisal Mosque, Pakistan'));
        $this->assertFalse($this->service->looksLikeCoordinates(null));
    }

    public function test_extract_coordinates_from_array_and_json_string(): void
    {
        $fromArray = $this->service->extractCoordinates([
            'latitude' => 33.7297,
            'longitude' => 73.0372,
        ]);

        $fromString = $this->service->extractCoordinates('33.7297,73.0372');
        $fromJson = $this->service->extractCoordinates('{"lat":33.7297,"lon":73.0372}');

        $this->assertSame(['lat' => 33.7297, 'lon' => 73.0372], $fromArray);
        $this->assertSame(['lat' => 33.7297, 'lon' => 73.0372], $fromString);
        $this->assertSame(['lat' => 33.7297, 'lon' => 73.0372], $fromJson);
    }

    public function test_extract_label_prefers_label_over_name(): void
    {
        $label = $this->service->extractLabelFromResponse([
            'results' => [
                [
                    'name' => 'Faisal Mosque',
                    'label' => 'Faisal Mosque, Pakistan',
                ],
            ],
        ]);

        $this->assertSame('Faisal Mosque, Pakistan', $label);
    }

    public function test_extract_label_falls_back_to_name(): void
    {
        $label = $this->service->extractLabelFromResponse([
            'results' => [
                [
                    'name' => 'Faisal Mosque',
                ],
            ],
        ]);

        $this->assertSame('Faisal Mosque', $label);
    }

    public function test_extract_country_code_from_results(): void
    {
        $countryCode = $this->service->extractCountryCodeFromResponse([
            'results' => [
                [
                    'label' => 'Lahore, Pakistan',
                    'country_code' => 'pk',
                ],
            ],
        ]);

        $this->assertSame('PK', $countryCode);
    }

    public function test_extract_country_code_from_feature_properties(): void
    {
        $countryCode = $this->service->extractCountryCodeFromResponse([
            'features' => [
                [
                    'properties' => [
                        'country_a' => 'ARE',
                    ],
                ],
            ],
        ]);

        $this->assertSame('ARE', $countryCode);
    }
}
