<?php

namespace App\Console\Commands;

use App\Services\DuePreBookingReleaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseDuePreBookings extends Command
{
    protected $signature = 'app:release-due-pre-bookings';

    protected $description = 'Release scheduled pre-bookings when their dispatch release time is due.';

    public function handle(DuePreBookingReleaseService $duePreBookingReleaseService): int
    {
        $tenants = DB::connection('central')->table('tenants')->get(['id']);
        $released = 0;

        foreach ($tenants as $tenant) {
            try {
                $tenantId = (string) $tenant->id;
                $this->setTenantDatabase($tenantId);

                if (!$this->hasReleaseColumns()) {
                    continue;
                }

                $released += $duePreBookingReleaseService->releaseDueForCurrentTenant($tenantId);
            } catch (\Throwable $e) {
                \Log::error('Due pre-booking release failed', [
                    'tenant' => $tenant->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Released {$released} due pre-booking(s).");

        return self::SUCCESS;
    }

    private function setTenantDatabase(string $database): void
    {
        config(['database.connections.tenant.database' => 'tenant' . $database]);

        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');
    }

    private function hasReleaseColumns(): bool
    {
        return Schema::connection('tenant')->hasColumn('bookings', 'dispatch_release_at')
            && Schema::connection('tenant')->hasColumn('bookings', 'dispatch_release_mode');
    }
}
