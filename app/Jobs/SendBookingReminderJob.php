<?php

namespace App\Jobs;

use App\Models\CompanyBooking;
use App\Services\BookingReminderService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SendBookingReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $bookingId,
        public string $tenantDatabase,
        public int $reminderMinutes
    ) {
    }

    public function handle(BookingReminderService $bookingReminderService): void
    {
        config([
            'database.connections.tenant.database' => 'tenant' . $this->tenantDatabase,
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');

        $booking = CompanyBooking::find($this->bookingId);
        if (!$booking) {
            return;
        }

        if (in_array($booking->booking_status, ['cancelled', 'completed'], true)) {
            return;
        }

        if ((int) $booking->reminder_minutes !== $this->reminderMinutes) {
            return;
        }

        if ($bookingReminderService->isAsapPickup($booking->pickup_time)) {
            return;
        }

        $title = 'Scheduled pickup reminder';
        $message = sprintf(
            'Booking %s pickup in %d minutes at %s on %s.',
            $booking->booking_id,
            $booking->reminder_minutes,
            $booking->pickup_location,
            Carbon::parse($booking->booking_date)->format('d M Y') . ' ' . $booking->pickup_time
        );

        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->timeout(5)->post(env('NODE_SOCKET_URL') . '/send-reminder', [
                'clientId' => $this->tenantDatabase,
                'tenantDb' => $this->tenantDatabase,
                'title' => $title,
                'description' => $message,
                'message' => $message,
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_id,
                'pickup_location' => $booking->pickup_location,
                'pickup_time' => $booking->pickup_time,
                'booking_date' => $booking->booking_date,
                'reminder_minutes' => $booking->reminder_minutes,
                'driver_id' => $booking->driver,
            ]);
        } catch (\Exception $e) {
            \Log::warning('Booking reminder socket call failed', [
                'booking_id' => $booking->id,
                'tenant' => $this->tenantDatabase,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
