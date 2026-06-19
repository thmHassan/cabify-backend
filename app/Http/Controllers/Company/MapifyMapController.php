<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\MapifyReverseGeocodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MapifyMapController extends Controller
{
    public function __construct(
        private readonly MapifyReverseGeocodingService $mapifyReverseGeocoding
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
                $path .= '/' . $z . '/' . $x . '/' . $y . '.' . ltrim((string) $extension, '.');
            }

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($baseUrl . $path, $request->query());

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
            $request->validate([
                'q' => 'required|string|max:255',
                'lat' => 'required|numeric',
                'lon' => 'required|numeric',
                'size' => 'nullable|integer|min:1|max:50',
            ]);

            $baseRequest = $this->mapifyBaseRequest();
            if ($baseRequest instanceof \Illuminate\Http\JsonResponse) {
                return $baseRequest;
            }
            ['token' => $token, 'baseUrl' => $baseUrl] = $baseRequest;

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($baseUrl . '/api/v1/proxy/search', $request->only(['q', 'lat', 'lon', 'size']));

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
            $request->validate([
                'q' => 'required|string|max:255',
                'lat' => 'required|numeric',
                'lon' => 'required|numeric',
                'boundary_country' => 'nullable|string|size:2',
            ]);

            $baseRequest = $this->mapifyBaseRequest();
            if ($baseRequest instanceof \Illuminate\Http\JsonResponse) {
                return $baseRequest;
            }
            ['token' => $token, 'baseUrl' => $baseUrl] = $baseRequest;

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($baseUrl . '/api/v1/proxy/geocoding', $request->only(['q', 'lat', 'lon', 'boundary_country']));

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
}
