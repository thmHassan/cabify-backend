<?php

namespace App\Services;

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
        array $data = []
    ) {
        $accessToken = self::getAccessToken();

         $response = Http::withToken($accessToken)
        ->post(
            'https://fcm.googleapis.com/v1/projects/' . env('FIREBASE_PROJECT_ID') . '/messages:send',
            [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],
                    'data' => $data,
                ],
            ]
        );

        \Log::info('FCM RESPONSE', [
            'token' => $deviceToken,
            'response' => $response->json(),
            'status' => $response->status(),
        ]);
    }
}
