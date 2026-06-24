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

        if ($database === '') {
            return [
                'configured' => false,
                'error' => 'Company Id is Invalid. Please contact your Company Admin',
                'status' => 400,
            ];
        }

        try {
            $tenantDb = self::resolveSchemaName($database);

            if ($tenantDb === null) {
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

    public static function resolveSchemaName(string $database): ?string
    {
        $database = trim($database);

        if ($database === '') {
            return null;
        }

        $candidates = [$database];

        $tenantId = self::extractTenantId($database);
        if ($tenantId !== null) {
            $storedName = self::storedDatabaseName($tenantId);
            if ($storedName !== null) {
                $candidates[] = $storedName;
            }

            $candidates[] = 'tenant_' . $tenantId;

            $prefix = (string) config('tenancy.database.prefix', 'tenant');
            $suffix = (string) config('tenancy.database.suffix', '');
            $candidates[] = $prefix . $tenantId . $suffix;
        }

        foreach (array_values(array_unique($candidates)) as $schema) {
            if (self::schemaExists($schema)) {
                return $schema;
            }
        }

        return null;
    }

    public static function extractTenantId(string $database): ?string
    {
        $database = trim($database);

        if ($database === '') {
            return null;
        }

        if (str_starts_with($database, 'tenant_')) {
            $tenantId = substr($database, strlen('tenant_'));

            return $tenantId !== '' ? $tenantId : null;
        }

        if (str_starts_with($database, 'tenant') && strlen($database) > strlen('tenant')) {
            $tenantId = substr($database, strlen('tenant'));

            return $tenantId !== '' ? $tenantId : null;
        }

        return $database;
    }

    private static function storedDatabaseName(string $tenantId): ?string
    {
        try {
            $raw = DB::connection('central')
                ->table('tenants')
                ->where('id', $tenantId)
                ->value('data');

            if ($raw === null) {
                return null;
            }

            $data = is_string($raw) ? json_decode($raw, true) : (array) $raw;
            $stored = $data['database'] ?? null;

            if (!is_string($stored)) {
                return null;
            }

            $stored = trim($stored);

            return $stored !== '' ? $stored : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function schemaExists(string $schema): bool
    {
        $exists = DB::selectOne(
            'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$schema]
        );

        return !empty($exists);
    }
}
