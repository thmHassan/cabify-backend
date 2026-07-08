<?php

namespace App\Services;

use App\Jobs\SendBookingReminderJob;
use App\Models\CompanyBooking;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

class BookingReminderService
{
    public const ALLOWED_MINUTES = [5, 15, 30, 50];

    public function isAsapPickup(?string $pickupTime): bool
    {
        return strtolower(trim((string) $pickupTime)) === 'asap';
    }

    public function validateReminderRequest(Request $request): void
    {
        if (!$request->filled('reminder_minutes')) {
            return;
        }

        $pickupTimeType = $request->filled('pickup_time_type')
            ? strtolower((string) $request->pickup_time_type)
            : null;

        $isScheduled = $pickupTimeType === 'time'
            || ($pickupTimeType !== 'asap' && !$this->isAsapPickup($request->pickup_time));

        if (!$isScheduled) {
            throw ValidationException::withMessages([
                'reminder_minutes' => 'Reminder minutes cannot be set for ASAP pickups.',
            ]);
        }

        $request->validate([
            'reminder_minutes' => 'integer|in:' . implode(',', self::ALLOWED_MINUTES),
        ]);
    }

    public function resolveReminderMinutes(Request $request): ?int
    {
        $pickupTimeType = $request->filled('pickup_time_type')
            ? strtolower((string) $request->pickup_time_type)
            : null;

        $isScheduled = $pickupTimeType === 'time'
            || ($pickupTimeType !== 'asap' && !$this->isAsapPickup($request->pickup_time));

        if (!$isScheduled || !$request->filled('reminder_minutes')) {
            return null;
        }

        return (int) $request->reminder_minutes;
    }

    public function scheduleReminder(CompanyBooking $booking, string $tenantDatabase): void
    {
        if (!$booking->reminder_minutes || !$booking->is_scheduled) {
            return;
        }

        if ($this->isAsapPickup($booking->pickup_time) || !$booking->booking_date) {
            return;
        }

        $remindAt = $this->resolveReminderDateTime($booking);
        if (!$remindAt || !$remindAt->isFuture()) {
            return;
        }

        if (Config::get('queue.default') === 'sync') {
            return;
        }

        SendBookingReminderJob::dispatch(
            $booking->id,
            $tenantDatabase,
            (int) $booking->reminder_minutes
        )->delay($remindAt);
    }

    public function resolveReminderDateTime(CompanyBooking $booking): ?Carbon
    {
        if (!$booking->booking_date || !$booking->pickup_time || !$booking->reminder_minutes) {
            return null;
        }

        if ($this->isAsapPickup($booking->pickup_time)) {
            return null;
        }

        try {
            $pickupAt = Carbon::parse($booking->booking_date . ' ' . $booking->pickup_time);

            return $pickupAt->copy()->subMinutes((int) $booking->reminder_minutes);
        } catch (\Exception $e) {
            return null;
        }
    }
}
