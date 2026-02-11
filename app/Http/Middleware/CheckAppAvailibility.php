<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAppAvailibility
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $setting = CompanySetting::orderBy("id", "DESC")->first();

        if(auth("driver") && $setting->driver_app == "disable"){
            return response()->json([
                'error' => 1,
                'message' => 'Driver App is not allowed by Company Admin'
            ], 400);
        }

        if(auth("rider") && $setting->customer_app == "disable"){
            return response()->json([
                'error' => 1,
                'message' => 'Rider App is not allowed by Company Admin'
            ], 400);
        }
        return $next($request);
    }
}
