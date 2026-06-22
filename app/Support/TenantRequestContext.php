<?php

namespace App\Support;

use Illuminate\Http\Request;

class TenantRequestContext
{
    public static function databaseId(Request $request): ?string
    {
        $database = $request->header('database') ?? $request->query('database');

        if (!filled($database)) {
            return null;
        }

        return trim((string) $database);
    }

    public static function bearerToken(Request $request): ?string
    {
        $token = $request->bearerToken();

        if (filled($token)) {
            return $token;
        }

        foreach (['token', 'access_token'] as $key) {
            $queryToken = $request->query($key);
            if (filled($queryToken)) {
                return trim((string) $queryToken);
            }
        }

        return null;
    }
}
