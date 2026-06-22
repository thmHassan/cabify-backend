<?php

namespace App\Support;

use Illuminate\Http\Request;

class TenantRequestContext
{
    public static function databaseId(Request $request): ?string
    {
        $database = $request->header('database') ?? $request->query('database');

        if (filled($database)) {
            return trim((string) $database);
        }

        return static::databaseIdFromToken(static::bearerToken($request));
    }

    public static function databaseIdFromToken(?string $token): ?string
    {
        if (!filled($token)) {
            return null;
        }

        try {
            $tenant = auth('tenant')->setToken($token)->user();
            if ($tenant) {
                return (string) $tenant->getAuthIdentifier();
            }

            $tenantId = auth('dispatcher')->setToken($token)->payload()->get('tenant_id');
            if (filled($tenantId)) {
                return trim((string) $tenantId);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
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

    public static function rateLimitKey(Request $request): string
    {
        $database = static::databaseId($request);
        $token = static::bearerToken($request);

        if ($database && $token) {
            return 'tenant:' . $database . ':' . sha1($token);
        }

        if ($database) {
            return 'tenant:' . $database;
        }

        if ($token) {
            return 'token:' . sha1($token);
        }

        return $request->ip() ?? 'guest';
    }
}
