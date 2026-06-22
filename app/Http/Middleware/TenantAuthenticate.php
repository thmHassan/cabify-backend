<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

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
            $token = $request->bearerToken();
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

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
        $database = $request->header('database');
        if (!$database) {
            return;
        }

        $expectedDb = 'tenant' . $database;
        if (config('database.connections.tenant.database') === $expectedDb) {
            return;
        }

        Config::set('database.connections.tenant', [
            'driver' => 'mysql',
            'host' => config('database.connections.central.host'),
            'port' => config('database.connections.central.port'),
            'database' => $expectedDb,
            'username' => config('database.connections.central.username'),
            'password' => config('database.connections.central.password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');
    }
}
