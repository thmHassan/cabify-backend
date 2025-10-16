<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Stancl\Tenancy\Database\Models\Tenant;

class SwitchTenantDatabase
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('tenant')->user();

        if (! $user || ! $user->id) {
            return response()->json(['message' => 'Tenant not associated'], 403);
        }

        $tenant = Tenant::find($user->id);

        if (! $tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        // Initialize tenant connection
        tenancy()->initialize($tenant);

        return $next($request);
    }
}
