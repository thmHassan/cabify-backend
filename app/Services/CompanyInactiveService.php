<?php

namespace App\Services;

use App\Models\Dispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CompanyInactiveService
{
    public static function handle(string $tenantId): void
    {
        self::invalidateDispatcherSessions($tenantId);
        self::notifySocketLogout($tenantId);
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

    public static function notifySocketLogout(string $tenantId): void
    {
        $body = [
            'client_id' => $tenantId,
            'changed_at' => now()->toISOString(),
        ];

        $headers = [
            'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
            'database' => $tenantId,
        ];

        $baseUrl = rtrim((string) config('services.node_socket.url'), '/');

        self::postSocketLogout($baseUrl . '/company-client-force-logout', $headers, $body, $tenantId, 'company_client');
        self::postSocketLogout($baseUrl . '/dispatcher-company-inactive-logout', $headers, $body, $tenantId, 'dispatcher');
    }

    private static function postSocketLogout(string $url, array $headers, array $body, string $tenantId, string $audience): void
    {
        try {
            Http::withHeaders($headers)->timeout(5)->post($url, $body);
        } catch (\Throwable $e) {
            Log::warning('Company inactive socket logout call failed', [
                'tenant_id' => $tenantId,
                'audience' => $audience,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
