<?php

namespace App\Services;

use App\Models\CompanyBooking;
use App\Models\CompanyUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BookingUpdateService
{
    public function __construct(
        private readonly PreBookingService $preBookingService,
        private readonly BookingReminderService $bookingReminderService
    ) {
    }

    public function assertEditable(CompanyBooking $booking): void
    {
        if ($booking->booking_status !== 'pending') {
            throw ValidationException::withMessages([
                'booking' => 'Only pending bookings can be edited.',
            ]);
        }

        if ($booking->dispatch_released) {
            throw ValidationException::withMessages([
                'booking' => 'Booking cannot be edited after dispatch has been released.',
            ]);
        }
    }

    public function update(CompanyBooking $booking, Request $request, string $tenantDatabase): CompanyBooking
    {
        $this->assertEditable($booking);

        if ($request->filled('phone_no')) {
            $booking->phone_no = $request->phone_no;

            if ($booking->user_id) {
                CompanyUser::where('id', $booking->user_id)->update([
                    'phone_no' => $request->phone_no,
                ]);
            }
        }

        if ($request->filled('passenger')) {
            $booking->passenger = $request->passenger;
        }

        $isScheduledBooking = $this->preBookingService->isScheduledBooking($booking);
        $scheduleFieldsProvided = $request->hasAny([
            'booking_date',
            'pickup_time',
            'pickup_time_type',
            'reminder_minutes',
        ]);

        if ($scheduleFieldsProvided) {
            if (!$isScheduledBooking) {
                throw ValidationException::withMessages([
                    'booking' => 'Schedule fields can only be edited on scheduled pre-bookings.',
                ]);
            }

            if ($request->filled('pickup_time_type') && $request->pickup_time_type !== 'time') {
                throw ValidationException::withMessages([
                    'pickup_time_type' => 'Only scheduled bookings with pickup_time_type=time can be edited here.',
                ]);
            }

            if ($request->filled('booking_date')) {
                $booking->booking_date = Carbon::parse($request->booking_date)->toDateString();
            }

            if ($request->filled('pickup_time')) {
                $booking->pickup_time = $request->pickup_time;
            }

            if ($request->filled('pickup_time_type')) {
                $booking->pickup_time_type = 'time';
                $booking->is_scheduled = true;
            }

            $this->bookingReminderService->validateReminderRequest($request);

            if ($request->has('reminder_minutes')) {
                $booking->reminder_minutes = $request->filled('reminder_minutes')
                    ? (int) $request->reminder_minutes
                    : null;
            }
        }

        $booking->save();

        if ($this->preBookingService->isScheduledBooking($booking) && !$booking->dispatch_released) {
            $this->preBookingService->scheduleDispatchRelease($booking, $tenantDatabase);
            $this->bookingReminderService->scheduleReminder($booking, $tenantDatabase);
        }

        return $booking->fresh(['driverDetail']);
    }

    public function formatBookingPayload(CompanyBooking $booking): array
    {
        return [
            'id' => $booking->id,
            'booking_id' => $booking->booking_id,
            'booking_date' => $booking->booking_date,
            'pickup_time' => $booking->pickup_time,
            'pickup_time_type' => $booking->pickup_time_type,
            'is_scheduled' => (bool) $booking->is_scheduled,
            'pre_booking' => $booking->pre_booking,
            'dispatch_released' => (bool) $booking->dispatch_released,
            'reminder_minutes' => $booking->reminder_minutes,
            'phone_no' => $booking->phone_no,
            'passenger' => $booking->passenger,
            'booking_status' => $booking->booking_status,
            'pending_driver_id' => $booking->pending_driver_id,
            'driver' => $booking->driver,
            'pickup_location' => $booking->pickup_location,
            'destination_location' => $booking->destination_location,
            'driverDetail' => $booking->driverDetail,
        ];
    }
}
