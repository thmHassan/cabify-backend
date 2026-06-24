<?php

namespace App\Services;

use App\Models\Dispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CompanyInactiveService
{
    public static function handle(string $tenantId, string $previousStatus = 'active'): void
    {
        self::invalidateDispatcherSessions($tenantId);
        self::notifySocketLogout($tenantId, $previousStatus);
    }

    private static function invalidateDispatcherSessions(string $tenantId): void
    {
        try {
            DispatcherSessionService::setTenantConnection($tenantId);

            if (!Schema::connection('tenant')->hasTable('dispatcher')) {
                return;
            }

            if (!Schema::connection('tenant')->hasColumn('dispatcher', 'auth_version')) {
                return;
            }

            Dispatcher::on('tenant')->increment('auth_version');
        } catch (\Throwable $e) {
            Log::warning('Company inactive dispatcher session invalidation failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifySocketLogout(string $tenantId, string $previousStatus = 'active'): void
    {
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $tenantId,
            ])->timeout(5)->post(
                SocketApiUrlResolver::endpoint(null, 'company/status-changed'),
                [
                    'client_id' => $tenantId,
                    'previous_status' => $previousStatus,
                    'new_status' => 'inactive',
                    'changed_at' => now()->toISOString(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Company inactive socket logout call failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
