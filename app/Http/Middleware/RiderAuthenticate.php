<?php

namespace App\Http\Middleware;

use App\Support\TenantRequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RiderAuthenticate
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

            $rider = auth('rider')->setToken($token)->userOrFail();

            if (!$rider) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $tokenAuthVersion = (int) auth('rider')->payload()->get('auth_version', 0);
            if ($tokenAuthVersion !== (int) ($rider->auth_version ?? 0)) {
                return response()->json(['message' => 'Token revoked'], 401);
            }

            $request->attributes->set('rider', $rider);

        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json(['message' => 'Token expired'], 401);
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json(['message' => 'Token invalid'], 401);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Unauthenticated', 'error' => $e->getMessage()], 401);
        }
        return $next($request);
    }
}
