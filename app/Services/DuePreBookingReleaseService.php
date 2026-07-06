<?php

namespace App\Services;

use App\Models\CompanyBooking;
use App\Models\CompanySetting;
use Carbon\Carbon;

class DuePreBookingReleaseService
{
    public function __construct(
        private readonly BookingDispatchService $bookingDispatchService
    ) {
    }

    public function releaseDueForCurrentTenant(
        string $tenantDatabase,
        ?string $socketApiBaseUrl = null,
        int $limit = 100
    ): int {
        $settings = CompanySetting::orderBy('id', 'DESC')->first();
        if (!CompanySetting::resolveReleaseSettings($settings)['enabled']) {
            return 0;
        }

        $released = 0;

        CompanyBooking::query()
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
            ->where('dispatch_release_at', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
            ->where(function ($query) {
                $query->whereNull('dispatch_release_mode')
                    ->orWhere('dispatch_release_mode', '!=', PreBookingService::RELEASE_MODE_MANUAL_REVIEW);
            })
            ->orderBy('dispatch_release_at')
            ->limit($limit)
            ->get()
            ->each(function (CompanyBooking $booking) use ($tenantDatabase, $socketApiBaseUrl, &$released) {
                $this->bookingDispatchService->releaseForDispatch($booking, $tenantDatabase, $socketApiBaseUrl);
                $released++;
            });

        CompanyBooking::query()
            ->where('booking_status', 'pending')
            ->where(function ($query) {
                $query->where('pickup_time_type', 'time')
                    ->orWhere('is_scheduled', true);
            })
            ->where('dispatch_released', true)
            ->whereNull('driver')
            ->whereNull('pending_driver_id')
            ->whereNotNull('dispatch_release_at')
            ->where('dispatch_release_at', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
            ->where(function ($query) {
                $query->whereNull('dispatch_release_mode')
                    ->orWhere('dispatch_release_mode', '!=', PreBookingService::RELEASE_MODE_MANUAL_REVIEW);
            })
            ->where('dispatcher_action', 'LIKE', '%scheduled for auto release%')
            ->orderBy('dispatch_release_at')
            ->limit($limit)
            ->get()
            ->each(function (CompanyBooking $booking) use ($tenantDatabase, $socketApiBaseUrl, &$released) {
                $this->bookingDispatchService->releaseForDispatch($booking, $tenantDatabase, $socketApiBaseUrl);
                $released++;
            });

        return $released;
    }
}
