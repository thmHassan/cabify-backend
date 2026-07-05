<?php

namespace App\Services;

use App\Models\CompanyDriver;
use App\Models\CompanyToken;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FCMService
{
    protected static ?string $accessToken = null;

    protected static function credentialsPath(): string
    {
        return env('FIREBASE_CREDENTIALS_PATH') ?: storage_path('app/firebase/firebase.json');
    }

    protected static function getAccessToken(): ?string
    {
        if (self::$accessToken) {
            return self::$accessToken;
        }

        $credentialsPath = self::credentialsPath();
        if (!is_readable($credentialsPath)) {
            Log::error('FCM credentials file is missing or unreadable', [
                'path' => $credentialsPath,
            ]);

            return null;
        }

        $credentialsJson = json_decode(file_get_contents($credentialsPath), true);
        if (!is_array($credentialsJson)) {
            Log::error('FCM credentials file is not valid JSON', [
                'path' => $credentialsPath,
            ]);

            return null;
        }

        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/firebase.messaging',
            $credentialsJson
        );

        $token = $credentials->fetchAuthToken();

        self::$accessToken = $token['access_token'] ?? null;

        if (!self::$accessToken) {
            Log::error('FCM access token could not be fetched', [
                'token_response' => $token,
            ]);
        }

        return self::$accessToken;
    }

    public static function sendToDevice(
        string $deviceToken,
        string $title,
        string $body,
        ?array $data = null
    ) {
        try {
            $deviceToken = trim($deviceToken);
            if ($deviceToken === '') {
                Log::warning('FCM send skipped: empty device token');
                return null;
            }

            $accessToken = self::getAccessToken();
            if (!$accessToken) {
                return null;
            }

            $message = [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
            ];

            if (is_array($data) && !empty($data)) {
                $message['data'] = collect($data)
                    ->map(fn ($v) => (string) $v)
                    ->toArray();
            }

            $response = Http::withToken($accessToken)
                ->post(
                    'https://fcm.googleapis.com/v1/projects/' . env('FIREBASE_PROJECT_ID') . '/messages:send',
                    [
                        'message' => $message
                    ]
                );

            Log::info('FCM RESPONSE', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $response;
        } catch (\Throwable $e) {
            Log::error('FCM send failed', [
                'error' => $e->getMessage(),
                'title' => $title,
                'token_prefix' => substr($deviceToken, 0, 12),
            ],
            );

            return null;
        }
    }

    public static function sendToDriver(int $driverId, string $title, string $body, array $data = []): void
    {
        $driver = CompanyDriver::find($driverId);

        $tokens = CompanyToken::where('user_id', $driverId)
            ->where('user_type', 'driver')
            ->pluck('fcm_token');

        if ($driver?->fcm_token) {
            $tokens->prepend($driver->fcm_token);
        }

        foreach ($tokens->unique()->filter() as $token) {
            self::sendToDevice($token, $title, $body, $data);
        }
    }
}
