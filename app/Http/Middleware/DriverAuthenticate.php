<?php

namespace App\Http\Middleware;

use App\Services\DriverDocumentExpiryService;
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

            auth('driver')->setToken($request->bearerToken());
            $driver = auth('driver')->userOrFail();

            if (!$driver) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $tokenAuthVersion = (int) auth('driver')->payload()->get('auth_version', 0);
            if ($tokenAuthVersion !== (int) ($driver->auth_version ?? 0)) {
                return response()->json(['message' => 'Token revoked'], 401);
            }

            $request->attributes->set('driver', $driver);

            $expiredDocuments = app(DriverDocumentExpiryService::class)->syncRestriction($driver);
            if ($expiredDocuments->isNotEmpty() && !$this->isExpiryRestrictionAllowedRoute($request)) {
                return response()->json(
                    app(DriverDocumentExpiryService::class)->restrictionPayload($expiredDocuments),
                    403
                );
            }

        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json(['message' => 'Token expired'], 401);
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json(['message' => 'Token invalid'], 401);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Unauthenticated', 'error' => $e->getMessage()], 401);
        }

        return $next($request);
    }

    private function isExpiryRestrictionAllowedRoute(Request $request): bool
    {
        return $request->is([
            'api/driver/document-list',
            'api/driver/document-upload',
            'api/driver/get-profile',
            'api/driver/update-profile',
            'api/driver/store-token',
            'api/driver/logout',
            'api/driver/get-mobile-setting',
            'api/driver/policies',
            'api/driver/faqs',
            'driver/document-list',
            'driver/document-upload',
            'driver/get-profile',
            'driver/update-profile',
            'driver/store-token',
            'driver/logout',
            'driver/get-mobile-setting',
            'driver/policies',
            'driver/faqs',
        ]);
    }
}
