<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DriverAuthenticate
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

            $driver = auth('driver')->setToken($request->bearerToken())->userOrFail();

            if (!$driver) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $request->attributes->set('driver', $driver);

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
