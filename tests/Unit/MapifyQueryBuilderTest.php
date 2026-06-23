<?php

namespace Tests\Unit;

use App\Support\MapifyQueryBuilder;
use Illuminate\Http\Request;
use Tests\TestCase;

class MapifyQueryBuilderTest extends TestCase
{
    public function test_build_search_query_prefers_request_country_when_nearby_search_enabled(): void
    {
        $request = Request::create('/company/mapify-search', 'GET', [
            'q' => 'hospital',
            'lat' => 31.5204,
            'lon' => 74.3587,
            'size' => 5,
            'boundary_country' => 'ARE',
        ]);

        $query = MapifyQueryBuilder::buildSearchQuery($request, true, 'PAK');

        $this->assertSame('ARE', $query['boundary_country']);
    }

    public function test_build_search_query_uses_resolved_country_when_nearby_search_enabled_without_request_country(): void
    {
        $request = Request::create('/company/mapify-search', 'GET', [
            'q' => 'hospital',
            'lat' => 31.5204,
            'lon' => 74.3587,
            'size' => 5,
        ]);

        $query = MapifyQueryBuilder::buildSearchQuery($request, true, 'PAK');

        $this->assertSame('hospital', $query['q']);
        $this->assertSame(31.5204, $query['lat']);
        $this->assertSame(74.3587, $query['lon']);
        $this->assertSame(50, $query['size']);
        $this->assertSame('PAK', $query['boundary_country']);
    }

    public function test_build_search_query_omits_boundary_country_when_nearby_search_disabled_without_country(): void
    {
        $request = Request::create('/company/mapify-search', 'GET', [
            'q' => 'Tokyo Japan',
            'size' => 3,
        ]);

        $query = MapifyQueryBuilder::buildSearchQuery($request, false);

        $this->assertSame('Tokyo Japan', $query['q']);
        $this->assertSame(3, $query['size']);
        $this->assertArrayNotHasKey('boundary_country', $query);
    }

    public function test_build_search_query_includes_boundary_country_when_nearby_search_disabled_with_country(): void
    {
        $request = Request::create('/company/mapify-search', 'GET', [
            'q' => 'Sheikh Zayed Road',
            'size' => 5,
            'boundary_country' => 'are',
        ]);

        $query = MapifyQueryBuilder::buildSearchQuery($request, false);

        $this->assertSame('Sheikh Zayed Road', $query['q']);
        $this->assertSame(5, $query['size']);
        $this->assertSame('ARE', $query['boundary_country']);
    }

    public function test_build_geocoding_query_prefers_request_country_when_nearby_search_enabled(): void
    {
        $request = Request::create('/company/mapify-geocoding', 'GET', [
            'q' => 'blue area',
            'lat' => 33.70,
            'lon' => 73.05,
            'boundary_country' => 'ARE',
        ]);

        $query = MapifyQueryBuilder::buildGeocodingQuery($request, true, 'PK');

        $this->assertSame('blue area', $query['q']);
        $this->assertSame(33.70, $query['lat']);
        $this->assertSame(73.05, $query['lon']);
        $this->assertSame('ARE', $query['boundary_country']);
    }

    public function test_build_geocoding_query_uses_resolved_country_when_nearby_search_enabled_without_request_country(): void
    {
        $request = Request::create('/company/mapify-geocoding', 'GET', [
            'q' => 'blue area',
            'lat' => 33.70,
            'lon' => 73.05,
        ]);

        $query = MapifyQueryBuilder::buildGeocodingQuery($request, true, 'PK');

        $this->assertSame('blue area', $query['q']);
        $this->assertSame(33.70, $query['lat']);
        $this->assertSame(73.05, $query['lon']);
        $this->assertSame('PK', $query['boundary_country']);
    }

    public function test_build_geocoding_query_includes_boundary_country_when_nearby_search_disabled_with_country(): void
    {
        $request = Request::create('/company/mapify-geocoding', 'GET', [
            'q' => 'blue area',
            'boundary_country' => 'PK',
        ]);

        $query = MapifyQueryBuilder::buildGeocodingQuery($request, false);

        $this->assertSame('blue area', $query['q']);
        $this->assertSame('PK', $query['boundary_country']);
    }

    public function test_parse_nearby_search_accepts_boolean_strings(): void
    {
        $request = Request::create('/company/mapify-search', 'GET', [
            'nearby_search' => 'true',
        ]);

        $this->assertTrue(MapifyQueryBuilder::parseNearbySearch($request));

        $request = Request::create('/company/mapify-search', 'GET', [
            'nearby_search' => '0',
        ]);

        $this->assertFalse(MapifyQueryBuilder::parseNearbySearch($request));
    }

    public function test_parse_nearby_search_accepts_one_and_zero(): void
    {
        $enabled = Request::create('/company/mapify-search', 'GET', [
            'nearby_search' => '1',
        ]);
        $disabled = Request::create('/company/mapify-search', 'GET', [
            'nearby_search' => '0',
        ]);

        $this->assertTrue(MapifyQueryBuilder::parseNearbySearch($enabled));
        $this->assertFalse(MapifyQueryBuilder::parseNearbySearch($disabled));
    }
}
