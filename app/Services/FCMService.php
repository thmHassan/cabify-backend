<?php

namespace App\Services;

use App\Models\CompanyDriver;
use App\Models\CompanyToken;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;

class FCMService
{
    protected static function getAccessToken(): string
    {
        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/firebase.messaging',
            json_decode(
                file_get_contents(storage_path('app/firebase/firebase.json')),
                true
            )
        );

        $token = $credentials->fetchAuthToken();

        return $token['access_token'];
    }

    public static function sendToDevice(
        string $deviceToken,
        string $title,
        string $body,
        array $data = null
    ) {
        $accessToken = self::getAccessToken();

        $message = [
            'token' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
        ];

        // ✅ DO NOT send data if empty
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
        \Log::info('FCM RESPONSE', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
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
