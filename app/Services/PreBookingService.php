<?php

namespace App\Services;

use App\Jobs\ReleasePreBookingJob;
use App\Models\CompanyBooking;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PreBookingService
{
    public const DISPATCH_LEAD_MINUTES = 60;

    public function resolvePickupTimeType(Request $request): string
    {
        if ($request->filled('pickup_time_type')) {
            return strtolower((string) $request->pickup_time_type);
        }

        return $this->isLegacyAsapPickup($request->pickup_time) ? 'asap' : 'time';
    }

    public function isScheduledRequest(Request $request): bool
    {
        return $this->resolvePickupTimeType($request) === 'time';
    }

    public function isScheduledBooking(CompanyBooking $booking): bool
    {
        if ($booking->pickup_time_type) {
            return $booking->pickup_time_type === 'time';
        }

        return (bool) $booking->is_scheduled;
    }

    public function isLegacyAsapPickup(?string $pickupTime): bool
    {
        return strtolower(trim((string) $pickupTime)) === 'asap';
    }

    public function applyPreBookingsFilter(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();
        $now = Carbon::now()->format('Y-m-d H:i:s');

        return $query
            ->where('is_scheduled', true)
            ->where('pickup_time_type', 'time')
            ->where('dispatch_released', false)
            ->where('booking_status', 'pending')
            ->where(function ($builder) use ($today, $now) {
                $builder->whereDate('booking_date', '>', $today)
                    ->orWhere(function ($inner) use ($today, $now) {
                        $inner->whereDate('booking_date', $today)
                            ->where('pickup_time', '!=', 'asap')
                            ->whereRaw('TIMESTAMP(booking_date, pickup_time) > ?', [$now]);
                    });
            });
    }

    public function bookingQualifiesAsPreBooking(CompanyBooking $booking): bool
    {
        if (!$booking->is_scheduled || $booking->pickup_time_type !== 'time' || $booking->dispatch_released) {
            return false;
        }

        if ($booking->booking_status !== 'pending') {
            return false;
        }

        if (!$booking->booking_date || $this->isLegacyAsapPickup($booking->pickup_time)) {
            return false;
        }

        try {
            $pickupAt = Carbon::parse($booking->booking_date . ' ' . $booking->pickup_time);

            return $pickupAt->isFuture();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function scheduleDispatchRelease(CompanyBooking $booking, string $tenantDatabase): void
    {
        if (!$this->isScheduledBooking($booking) || $booking->dispatch_released) {
            return;
        }

        $releaseAt = $this->resolveDispatchReleaseDateTime($booking);
        if (!$releaseAt) {
            return;
        }

        $job = ReleasePreBookingJob::dispatch(
            $booking->id,
            $tenantDatabase,
            $this->resolvePickupSnapshot($booking)
        );

        if ($releaseAt->isFuture()) {
            $job->delay($releaseAt);
        }
    }

    public function resolveDispatchReleaseDateTime(CompanyBooking $booking): ?Carbon
    {
        if (!$booking->booking_date || !$booking->pickup_time || $this->isLegacyAsapPickup($booking->pickup_time)) {
            return null;
        }

        try {
            $pickupAt = Carbon::parse($booking->booking_date . ' ' . $booking->pickup_time);
            $leadMinutes = (int) env('PRE_BOOKING_DISPATCH_LEAD_MINUTES', self::DISPATCH_LEAD_MINUTES);

            return $pickupAt->copy()->subMinutes($leadMinutes);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function resolvePickupSnapshot(CompanyBooking $booking): ?string
    {
        if (!$booking->booking_date || !$booking->pickup_time || $this->isLegacyAsapPickup($booking->pickup_time)) {
            return null;
        }

        try {
            return Carbon::parse($booking->booking_date . ' ' . $booking->pickup_time)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}
