<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class SetTenantDatabase
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $database = $request->header('database');

        if (!$database) {
            return response()->json([
                'error' => 1,
                'message' => 'Database header is missing.'
            ], 400);
        }

        $tenantDb = 'tenant' . $database;
        try {
             $exists = DB::selectOne(
                    "SELECT SCHEMA_NAME 
                    FROM INFORMATION_SCHEMA.SCHEMATA 
                    WHERE SCHEMA_NAME = ?",
                    [$tenantDb]
                );


            if (empty($exists)) {
                return response()->json([
                    'error' => 1,
                    'message' => "Company Id is Invalid. Please contact your Company Admin"
                ], 400);
            }
        } catch (QueryException $e) {
            return response()->json([
                'error' => 1,
                'message' => 'Unable to verify tenant database.',
                'details' => $e->getMessage()
            ], 500);
        }

         Config::set('database.connections.tenant', [
            'driver' => 'mysql',
            'host' => config('database.connections.central.host'),
            'port' => config('database.connections.central.port'),
            'database' => "tenant".$database,
            'username' => config('database.connections.central.username'),
            'password' => config('database.connections.central.password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ]);

        // Reset and reconnect
        DB::purge('tenant');
        DB::reconnect('tenant');

        Config::set('database.default', 'tenant');

        return $next($request);
    }
}
