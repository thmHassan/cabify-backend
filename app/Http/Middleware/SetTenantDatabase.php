<?php

namespace App\Http\Middleware;

use App\Support\TenantDatabaseConfigurator;
use App\Support\TenantRequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantDatabase
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $database = TenantRequestContext::databaseId($request);

        if (!$database) {
            $tenant = auth('tenant')->user();
            if ($tenant) {
                $database = (string) $tenant->getAuthIdentifier();
            }
        }

        if (!$database) {
            $token = TenantRequestContext::bearerToken($request);
            $database = TenantRequestContext::databaseIdFromToken($token);
        }

        if (!$database) {
            return response()->json([
                'error' => 1,
                'message' => 'Invalid Data',
            ], 400);
        }

        $result = TenantDatabaseConfigurator::configure($database);
        if (!$result['configured']) {
            return response()->json([
                'error' => 1,
                'message' => $result['error'] ?? 'Unable to configure tenant database.',
            ], $result['status'] ?? 500);
        }

        return $next($request);
    }
}
