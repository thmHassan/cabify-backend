<?php

namespace App\Services;

use Illuminate\Http\Request;

class SocketApiUrlResolver
{
    public static function resolve(?Request $request = null): string
    {
        $candidates = [];

        if ($request) {
            $candidates[] = $request->input('socket_api_url');
            $candidates[] = $request->input('socket_api_base_url');
            $candidates[] = $request->header('socket-api-url');
            $candidates[] = $request->header('socket-api-base-url');
        }

        $candidates[] = config('services.node_socket.url');

        foreach ($candidates as $candidate) {
            $normalized = self::normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        throw new \RuntimeException('Socket API base URL is not configured.');
    }

    public static function endpoint(?Request $request, string $path): string
    {
        return self::resolve($request) . '/' . ltrim($path, '/');
    }

    private static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $value)) {
            return null;
        }

        return rtrim($value, '/');
    }
}
