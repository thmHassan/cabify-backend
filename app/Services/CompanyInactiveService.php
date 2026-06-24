<?php

namespace App\Services;

use App\Models\Dispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CompanyInactiveService
{
    public static function normalizeStatus(mixed $status): string
    {
        $value = strtolower(trim((string) ($status ?? '')));

        if (in_array($value, ['inactive', 'deactive', 'disabled', 'disable', '0', 'false'], true)) {
            return 'inactive';
        }

        if ($value === '' || in_array($value, ['active', '1', 'true', 'enable', 'enabled'], true)) {
            return 'active';
        }

        return $value;
    }

    public static function isInactive(mixed $status): bool
    {
        return self::normalizeStatus($status) === 'inactive';
    }

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
        $socketUrl = rtrim((string) (config('services.node_socket.url') ?: env('NODE_SOCKET_URL', '')), '/');
        $secret = (string) (config('services.node_socket.internal_secret') ?: env('NODE_INTERNAL_SECRET', ''));

        if ($socketUrl === '' || $secret === '') {
            Log::warning('Company inactive socket call skipped: socket URL or internal secret is not configured', [
                'tenant_id' => $tenantId,
                'socket_url_configured' => $socketUrl !== '',
                'secret_configured' => $secret !== '',
            ]);

            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secret,
                'database' => $tenantId,
            ])->timeout(5)->post(
                $socketUrl . '/company/status-changed',
                [
                    'client_id' => $tenantId,
                    'previous_status' => self::normalizeStatus($previousStatus),
                    'new_status' => 'inactive',
                    'changed_at' => now()->toISOString(),
                ]
            );

            if (!$response->successful()) {
                Log::warning('Company inactive socket call returned error', [
                    'tenant_id' => $tenantId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'url' => $socketUrl . '/company/status-changed',
                ]);

                return;
            }

            Log::info('Company inactive socket event dispatched', [
                'tenant_id' => $tenantId,
                'response' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Company inactive socket logout call failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
