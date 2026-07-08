<?php

namespace App\Console\Commands;

use App\Jobs\SendBookingReminderJob;
use App\Models\CompanyBooking;
use App\Services\BookingReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SendDueBookingReminders extends Command
{
    protected $signature = 'app:send-due-booking-reminders';

    protected $description = 'Send scheduled booking reminders when their reminder time is due.';

    public function handle(BookingReminderService $bookingReminderService): int
    {
        $tenants = DB::connection('central')->table('tenants')->get(['id']);
        $sent = 0;

        foreach ($tenants as $tenant) {
            try {
                $tenantId = (string) $tenant->id;
                $this->setTenantDatabase($tenantId);

                if (!$this->hasReminderColumns()) {
                    continue;
                }

                $sent += $this->sendDueForCurrentTenant($tenantId, $bookingReminderService);
            } catch (\Throwable $e) {
                \Log::error('Due booking reminder failed', [
                    'tenant' => $tenant->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} due booking reminder(s).");

        return self::SUCCESS;
    }

    public function sendDueForCurrentTenant(
        string $tenantId,
        BookingReminderService $bookingReminderService
    ): int {
        $sent = 0;

        CompanyBooking::query()
            ->where('is_scheduled', true)
            ->whereNotNull('reminder_minutes')
            ->whereNull('reminder_sent_at')
            ->whereNotIn('booking_status', ['cancelled', 'completed', 'no_show'])
            ->orderBy('booking_date')
            ->orderBy('pickup_time')
            ->chunkById(100, function ($bookings) use ($tenantId, $bookingReminderService, &$sent) {
                foreach ($bookings as $booking) {
                    $remindAt = $bookingReminderService->resolveReminderDateTime($booking);
                    if (!$remindAt || $remindAt->isFuture()) {
                        continue;
                    }

                    (new SendBookingReminderJob(
                        (int) $booking->id,
                        $tenantId,
                        (int) $booking->reminder_minutes
                    ))->handle($bookingReminderService);

                    $booking->refresh();
                    if ($booking->reminder_sent_at) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    private function setTenantDatabase(string $database): void
    {
        config(['database.connections.tenant.database' => 'tenant' . $database]);

        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');
    }

    private function hasReminderColumns(): bool
    {
        return Schema::connection('tenant')->hasColumn('bookings', 'reminder_minutes')
            && Schema::connection('tenant')->hasColumn('bookings', 'reminder_sent_at');
    }
}
