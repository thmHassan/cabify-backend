<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\MapifyReverseGeocodingService;
use App\Services\MapSearchPreferenceService;
use App\Support\MapifyQueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class MapifyMapController extends Controller
{
    public function __construct(
        private readonly MapifyReverseGeocodingService $mapifyReverseGeocoding,
        private readonly MapSearchPreferenceService $mapSearchPreference
    ) {
    }

    private function mapifyBaseRequest()
    {
        $token = config('services.mapify.api_token');
        $baseUrl = rtrim((string) config('services.mapify.base_url'), '/');

        if (!filled($token)) {
            return response()->json([
                'error' => 1,
                'message' => 'Mapify API token is not configured.',
            ], 500);
        }

        return compact('token', 'baseUrl');
    }

    public function brightTiles(Request $request, ?string $z = null, ?string $x = null, ?string $y = null)
    {
        try {
            $baseRequest = $this->mapifyBaseRequest();
            if ($baseRequest instanceof \Illuminate\Http\JsonResponse) {
                return $baseRequest;
            }
            ['token' => $token, 'baseUrl' => $baseUrl] = $baseRequest;

            $path = '/api/v1/proxy/tiles/bright';
            if ($z !== null && $x !== null && $y !== null) {
                $extension = $request->query('ext', 'png');
                if (preg_match('/^(.+)\.([a-zA-Z0-9]+)$/', (string) $y, $matches)) {
                    $y = $matches[1];
                    $extension = $matches[2];
                }

                $path .= '/' . $z . '/' . $x . '/' . $y . '.' . ltrim((string) $extension, '.');
            }

            $query = collect($request->query())
                ->except(['database', 'token', 'access_token', 'ext'])
                ->all();

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($baseUrl . $path, $query);

            if ($response->failed()) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Failed to fetch Mapify tiles.',
                    'status' => $response->status(),
                    'details' => $response->json() ?? $response->body(),
                ], $response->status());
            }

            $contentType = $response->header('Content-Type') ?? 'application/json';

            if (str_contains($contentType, 'application/json')) {
                return response()->json([
                    'success' => 1,
                    'data' => $response->json(),
                ]);
            }

            return response($response->body(), $response->status())
                ->header('Content-Type', $contentType);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function search(Request $request)
    {
        try {
            $nearbySearch = MapifyQueryBuilder::parseNearbySearch($request);

            $request->validate([
                'q' => 'required|string|max:255',
                'lat' => 'nullable|numeric|required_with:lon',
                'lon' => 'nullable|numeric|required_with:lat',
                'size' => 'nullable|integer|min:1|max:50',
                'nearby_search' => 'nullable|boolean',
                'boundary_country' => [
                    Rule::requiredIf($nearbySearch),
                    'nullable',
                    'string',
                    'min:2',
                    'max:3',
                    'regex:/^[A-Za-z]{2,3}$/',
                ],
            ]);

            if ($request->has('nearby_search')) {
                $this->mapSearchPreference->save(
                    $nearbySearch,
                    $request->input('boundary_country')
                );
            }

            $baseRequest = $this->mapifyBaseRequest();
            if ($baseRequest instanceof \Illuminate\Http\JsonResponse) {
                return $baseRequest;
            }
            ['token' => $token, 'baseUrl' => $baseUrl] = $baseRequest;

            $query = MapifyQueryBuilder::buildSearchQuery($request, $nearbySearch);

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($baseUrl . '/api/v1/proxy/search', $query);

            if ($response->failed()) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Failed to fetch Mapify search results.',
                    'status' => $response->status(),
                    'details' => $response->json() ?? $response->body(),
                ], $response->status());
            }

            return response()->json([
                'success' => 1,
                'data' => $response->json(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function geocoding(Request $request)
    {
        try {
            $nearbySearch = MapifyQueryBuilder::parseNearbySearch($request);

            $request->validate([
                'q' => 'required|string|max:255',
                'lat' => 'nullable|numeric|required_with:lon',
                'lon' => 'nullable|numeric|required_with:lat',
                'nearby_search' => 'nullable|boolean',
                'boundary_country' => [
                    Rule::requiredIf($nearbySearch),
                    'nullable',
                    'string',
                    'min:2',
                    'max:3',
                    'regex:/^[A-Za-z]{2,3}$/',
                ],
            ]);

            if ($request->has('nearby_search')) {
                $this->mapSearchPreference->save(
                    $nearbySearch,
                    $request->input('boundary_country')
                );
            }

            $baseRequest = $this->mapifyBaseRequest();
            if ($baseRequest instanceof \Illuminate\Http\JsonResponse) {
                return $baseRequest;
            }
            ['token' => $token, 'baseUrl' => $baseUrl] = $baseRequest;

            $query = MapifyQueryBuilder::buildGeocodingQuery($request, $nearbySearch);

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($baseUrl . '/api/v1/proxy/geocoding', $query);

            if ($response->failed()) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Failed to fetch Mapify geocoding results.',
                    'status' => $response->status(),
                    'details' => $response->json() ?? $response->body(),
                ], $response->status());
            }

            return response()->json([
                'success' => 1,
                'data' => $response->json(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function reverseGeocoding(Request $request)
    {
        try {
            $request->validate([
                'lat' => 'required|numeric',
                'lon' => 'required|numeric',
                'size' => 'nullable|integer|min:1|max:50',
            ]);

            $baseRequest = $this->mapifyBaseRequest();
            if ($baseRequest instanceof \Illuminate\Http\JsonResponse) {
                return $baseRequest;
            }
            ['token' => $token, 'baseUrl' => $baseUrl] = $baseRequest;

            $size = (int) ($request->input('size', 1));
            $query = [
                'lat' => $request->input('lat'),
                'lon' => $request->input('lon'),
                'size' => $size,
            ];

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($baseUrl . '/api/v1/proxy/reverse_geocoding', $query);

            if ($response->failed()) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Failed to fetch Mapify reverse geocoding results.',
                    'status' => $response->status(),
                    'details' => $response->json() ?? $response->body(),
                ], $response->status());
            }

            $payload = $response->json();

            return response()->json([
                'success' => 1,
                'data' => $payload,
                'label' => is_array($payload)
                    ? $this->mapifyReverseGeocoding->extractLabelFromResponse($payload)
                    : null,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getMapSearchPreferences()
    {
        try {
            $preferences = $this->mapSearchPreference->resolve();

            return response()->json([
                'success' => 1,
                'nearby_search_enabled' => $preferences['nearby_search_enabled'],
                'search_boundary_country' => $preferences['search_boundary_country'],
                'map_search_preferences' => $preferences,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveMapSearchPreferences(Request $request)
    {
        try {
            $nearbySearch = MapifyQueryBuilder::parseNearbySearch($request);

            $request->validate([
                'nearby_search' => 'required|boolean',
                'boundary_country' => [
                    Rule::requiredIf($nearbySearch),
                    'nullable',
                    'string',
                    'min:2',
                    'max:3',
                    'regex:/^[A-Za-z]{2,3}$/',
                ],
            ]);

            $this->mapSearchPreference->save(
                $nearbySearch,
                $request->input('boundary_country')
            );

            $preferences = $this->mapSearchPreference->resolve();

            return response()->json([
                'success' => 1,
                'message' => 'Map search preferences saved successfully.',
                'nearby_search_enabled' => $preferences['nearby_search_enabled'],
                'search_boundary_country' => $preferences['search_boundary_country'],
                'map_search_preferences' => $preferences,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
