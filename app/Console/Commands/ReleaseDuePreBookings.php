<?php

namespace App\Console\Commands;

use App\Models\CompanyBooking;
use App\Models\CompanySetting;
use App\Services\BookingDispatchService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseDuePreBookings extends Command
{
    protected $signature = 'app:release-due-pre-bookings';

    protected $description = 'Release scheduled pre-bookings when their dispatch release time is due.';

    public function handle(BookingDispatchService $bookingDispatchService): int
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

                $settings = CompanySetting::on('tenant')->orderBy('id', 'DESC')->first();
                if (!CompanySetting::resolveReleaseSettings($settings)['enabled']) {
                    continue;
                }

                CompanyBooking::on('tenant')
                    ->where('booking_status', 'pending')
                    ->where(function ($query) {
                        $query->where('pickup_time_type', 'time')
                            ->orWhere('is_scheduled', true);
                    })
                    ->where(function ($query) {
                        $query->whereNull('dispatch_released')
                            ->orWhere('dispatch_released', false);
                    })
                    ->whereNotNull('dispatch_release_at')
                    ->where('dispatch_release_at', '<=', Carbon::now())
                    ->where(function ($query) {
                        $query->whereNull('dispatch_release_mode')
                            ->orWhere('dispatch_release_mode', '!=', 'manual_review');
                    })
                    ->orderBy('dispatch_release_at')
                    ->limit(100)
                    ->get()
                    ->each(function (CompanyBooking $booking) use ($bookingDispatchService, $tenantId, &$released) {
                        $bookingDispatchService->releaseForDispatch($booking, $tenantId);
                        $released++;
                    });
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
    }

    private function hasReleaseColumns(): bool
    {
        return Schema::connection('tenant')->hasColumn('bookings', 'dispatch_release_at')
            && Schema::connection('tenant')->hasColumn('bookings', 'dispatch_release_mode');
    }
}
