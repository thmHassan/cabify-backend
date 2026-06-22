<?php

namespace App\Http\Middleware;

use App\Support\TenantDatabaseConfigurator;
use App\Support\TenantRequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantAuthenticate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $token = TenantRequestContext::bearerToken($request);
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $this->ensureTenantDatabaseConfigured($request);

            $tenant = auth('tenant')->setToken($token)->user();

            if (!$tenant) {
                $this->ensureTenantDatabaseConfigured($request);

                $dispatcher = auth('dispatcher')->setToken($token)->user();
                if (!$dispatcher) {
                    return response()->json(['message' => 'Unauthenticated'], 401);
                }

                $tokenAuthVersion = (int) auth('dispatcher')->payload()->get('auth_version', 0);
                if ($tokenAuthVersion !== (int) ($dispatcher->auth_version ?? 0)) {
                    return response()->json(['message' => 'Token revoked'], 401);
                }
            }

            $request->attributes->set('tenant', $tenant);

        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json(['message' => 'Token expired'], 401);
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json(['message' => 'Token invalid'], 401);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Unauthenticated', 'error' => $e->getMessage()], 401);
        }

        return $next($request);
    }

    private function ensureTenantDatabaseConfigured(Request $request): void
    {
        $database = TenantRequestContext::databaseId($request);
        if (!$database) {
            return;
        }

        TenantDatabaseConfigurator::configure($database);
    }
}
