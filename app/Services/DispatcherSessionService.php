<?php

namespace App\Services;

use App\Models\Dispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DispatcherSessionService
{
    public static function invalidateAll(): int
    {
        return Dispatcher::query()->increment('auth_version');
    }

    public static function setTenantConnection(string $tenantId): void
    {
        Config::set('database.connections.tenant.database', 'tenant' . $tenantId);
        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    /**
     * @return array<int, array{tenant_id: string, dispatchers_affected: int}>
     */
    public static function invalidateAcrossAllTenants(): array
    {
        $results = [];

        $tenants = DB::connection('central')->table('tenants')->get(['id']);

        foreach ($tenants as $tenant) {
            try {
                self::setTenantConnection($tenant->id);

                if (!Schema::connection('tenant')->hasTable('dispatcher')) {
                    continue;
                }

                if (!Schema::connection('tenant')->hasColumn('dispatcher', 'auth_version')) {
                    continue;
                }

                $affected = Dispatcher::on('tenant')->increment('auth_version');

                if ($affected > 0) {
                    self::notifySocketLogout($tenant->id);
                }

                $results[] = [
                    'tenant_id' => $tenant->id,
                    'dispatchers_affected' => $affected,
                ];
            } catch (\Throwable $e) {
                Log::warning('Dispatcher logout failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    public static function notifySocketLogout(string $tenantId): void
    {
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $tenantId,
            ])->timeout(5)->post(rtrim((string) config('services.node_socket.url'), '/') . '/dispatcher-force-logout-all', []);
        } catch (\Throwable $e) {
            Log::warning('Dispatcher force logout socket call failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
