<?php

namespace App\Jobs;

use App\Models\CompanyBooking;
use App\Services\BookingDispatchService;
use App\Services\PreBookingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ReleasePreBookingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $bookingId,
        public string $tenantDatabase,
        public ?string $expectedPickupAt = null
    ) {
    }

    public function handle(BookingDispatchService $bookingDispatchService): void
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

        if ($this->expectedPickupAt) {
            $currentPickupAt = app(PreBookingService::class)->resolvePickupSnapshot($booking);
            if ($currentPickupAt !== $this->expectedPickupAt) {
                return;
            }
        }

        $bookingDispatchService->releaseForDispatch($booking, $this->tenantDatabase);
    }
}
