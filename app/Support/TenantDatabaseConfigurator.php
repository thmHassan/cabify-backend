<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantDatabaseConfigurator
{
    /**
     * @return array{configured: bool, error?: string, status?: int}
     */
    public static function configure(string $database): array
    {
        $database = trim($database);
        $tenantDb = 'tenant' . $database;

        try {
            $exists = DB::selectOne(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$tenantDb]
            );

            if (empty($exists)) {
                return [
                    'configured' => false,
                    'error' => 'Company Id is Invalid. Please contact your Company Admin',
                    'status' => 400,
                ];
            }
        } catch (QueryException $e) {
            return [
                'configured' => false,
                'error' => 'Unable to verify tenant database.',
                'status' => 500,
            ];
        }

        if (config('database.connections.tenant.database') === $tenantDb) {
            Config::set('database.default', 'tenant');

            return ['configured' => true];
        }

        Config::set('database.connections.tenant', [
            'driver' => 'mysql',
            'host' => config('database.connections.central.host'),
            'port' => config('database.connections.central.port'),
            'database' => $tenantDb,
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

        return ['configured' => true];
    }
}
