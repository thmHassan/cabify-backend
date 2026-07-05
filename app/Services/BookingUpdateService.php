<?php

namespace App\Services;

use App\Models\CompanyBooking;
use App\Models\CompanyUser;
use App\Support\VehicleDispatchFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BookingUpdateService
{
    public function __construct(
        private readonly PreBookingService $preBookingService,
        private readonly BookingReminderService $bookingReminderService,
        private readonly BookingDispatchService $bookingDispatchService
    ) {
    }

    public function assertEditable(Request $request, CompanyBooking $booking): void
    {
        if ($booking->booking_status !== 'pending') {
            throw ValidationException::withMessages([
                'booking' => 'Only pending bookings can be edited.',
            ]);
        }
    }

    public function update(CompanyBooking $booking, Request $request, string $tenantDatabase): CompanyBooking
    {
        $this->assertEditable($request, $booking);

        $this->syncPassengerUser($booking, $request);

        if ($booking->dispatch_released) {
            $this->applyReleasedBookingSafeFields($booking, $request);
            $booking->save();

            return $booking->fresh([
                'driverDetail',
                'vehicleDetail',
                'subCompanyDetail',
                'accountDetail',
            ]);
        }

        $this->applyFields($booking, $request);
        $isScheduled = $this->reconcileDispatchState($booking, $request);
        $this->preBookingService->applyDispatchReleaseDefaults($booking, $request);

        if ($request->has('reminder_minutes')) {
            $booking->reminder_minutes = $request->filled('reminder_minutes')
                ? (int) $request->reminder_minutes
                : null;
        }

        $booking->save();

        if ($isScheduled && !$booking->dispatch_released) {
            $this->preBookingService->scheduleDispatchRelease($booking, $tenantDatabase);
            $this->bookingReminderService->scheduleReminder($booking, $tenantDatabase);
        } elseif (!$isScheduled && !$booking->dispatch_released) {
            $this->bookingDispatchService->releaseForDispatch($booking, $tenantDatabase);
        }

        return $booking->fresh([
            'driverDetail',
            'vehicleDetail',
            'subCompanyDetail',
            'accountDetail',
        ]);
    }

    private function syncPassengerUser(CompanyBooking $booking, Request $request): void
    {
        if (!$request->hasAny(['phone_no', 'name', 'email'])) {
            return;
        }

        if ($request->filled('phone_no')) {
            $booking->phone_no = $request->phone_no;
        }

        if ($request->has('name')) {
            $booking->name = $request->name;
        }

        if ($request->has('email')) {
            $booking->email = $request->email;
        }

        if (!$booking->user_id) {
            if ($request->filled('phone_no')) {
                $user = CompanyUser::firstOrCreate(
                    ['phone_no' => $request->phone_no],
                    [
                        'name' => $request->input('name', $booking->name),
                        'email' => $request->input('email', $booking->email),
                    ]
                );
                $booking->user_id = $user->id;
            }

            return;
        }

        $userUpdates = array_filter([
            'phone_no' => $request->input('phone_no'),
            'name' => $request->input('name'),
            'email' => $request->input('email'),
        ], fn ($value) => $value !== null);

        if ($userUpdates !== []) {
            CompanyUser::where('id', $booking->user_id)->update($userUpdates);
        }
    }

    private function applyFields(CompanyBooking $booking, Request $request): void
    {
        $scalarFields = [
            'sub_company',
            'pickup_time',
            'booking_type',
            'pickup_location',
            'pickup_plot_id',
            'destination_location',
            'destination_plot_id',
            'tel_no',
            'journey_type',
            'vehicle',
            'passenger',
            'luggage',
            'hand_luggage',
            'special_request',
            'payment_reference',
            'booking_system',
            'bidding_fallback',
            'parking_charge',
            'waiting_charge',
            'ac_fares',
            'return_ac_fares',
            'ac_parking_charge',
            'ac_waiting_charge',
            'extra_charge',
            'toll',
            'dispatcher_id',
            'week',
            'start_at',
            'end_at',
            'payment_method',
            'multi_booking',
        ];

        foreach ($scalarFields as $field) {
            if ($request->has($field)) {
                $booking->{$field} = $request->input($field);
            }
        }

        if ($request->has('bidding_fallback')) {
            $booking->bidding_fallback = filter_var($request->input('bidding_fallback'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->has('vehicle') || $request->has('request_for_vehicle')) {
            $booking->vehicle = VehicleDispatchFilter::normalizeRequestedVehicle($request);
        }

        if ($request->has('multi_days')) {
            $booking->multi_days = is_array($request->multi_days)
                ? implode(',', $request->multi_days)
                : $request->multi_days;
        }

        if ($request->filled('booking_date')) {
            $booking->booking_date = Carbon::parse($request->booking_date)->toDateString();
        }

        if ($request->has('pickup_point')) {
            $booking->pickup_point = is_array($request->pickup_point)
                ? json_encode($request->pickup_point)
                : $request->input('pickup_point');
        }

        if ($request->has('destination_point')) {
            $booking->destination_point = is_array($request->destination_point)
                ? json_encode($request->destination_point)
                : $request->input('destination_point');
        }

        if ($request->has('via_point')) {
            $booking->via_point = json_encode($request->via_point);
        }

        if ($request->has('via_location')) {
            $booking->via_location = json_encode($request->via_location);
        }

        if ($request->has('distance')) {
            $booking->distance = $request->distance;
        }

        if ($request->has('booking_amount')) {
            $booking->booking_amount = $request->booking_amount;
            $booking->recommended_amount = $request->booking_amount;
            $booking->offered_amount = $request->booking_amount;
        }

        if ($request->has('account') || $request->has('account_id')) {
            $booking->account = $this->resolveAccountFromRequest($request);
        }

        if ($request->hasAny(['pickup_time_type', 'pickup_time'])) {
            $pickupTimeType = $this->resolvePickupTimeTypeForUpdate($booking, $request);
            $booking->pickup_time_type = $pickupTimeType;
            $booking->is_scheduled = $pickupTimeType === 'time';
        }

        if ($request->has('driver')) {
            $this->applyDriverAssignment($booking, $request);
        }
    }

    private function applyReleasedBookingSafeFields(CompanyBooking $booking, Request $request): void
    {
        $safeFields = [
            'tel_no',
            'special_request',
        ];

        foreach ($safeFields as $field) {
            if ($request->has($field)) {
                $booking->{$field} = $request->input($field);
            }
        }
    }

    private function reconcileDispatchState(CompanyBooking $booking, Request $request): bool
    {
        $isScheduled = $this->preBookingService->isScheduledBooking($booking);

        if ($isScheduled) {
            $booking->dispatch_released = false;

            if (!$request->has('driver') && $booking->driver && !$booking->pending_driver_id) {
                $booking->pending_driver_id = $booking->driver;
                $booking->driver = null;
            }

            return true;
        }

        if (!$request->has('driver') && $booking->pending_driver_id && !$booking->driver) {
            $booking->driver = $booking->pending_driver_id;
        }

        $booking->pending_driver_id = null;

        return false;
    }

    private function resolvePickupTimeTypeForUpdate(CompanyBooking $booking, Request $request): string
    {
        if ($request->filled('pickup_time_type')) {
            return strtolower((string) $request->pickup_time_type);
        }

        $pickupTime = $request->input('pickup_time', $booking->pickup_time);

        return $this->preBookingService->isLegacyAsapPickup($pickupTime) ? 'asap' : 'time';
    }

    private function applyDriverAssignment(CompanyBooking $booking, Request $request): void
    {
        $driverId = $request->input('driver');
        $isScheduled = $this->preBookingService->isScheduledBooking($booking);

        if ($isScheduled && filled($driverId)) {
            $booking->pending_driver_id = $driverId;
            $booking->driver = null;

            return;
        }

        $booking->driver = $driverId;
        $booking->pending_driver_id = null;
    }

    private function resolveAccountFromRequest(Request $request): ?string
    {
        $account = $request->input('account') ?? $request->input('account_id');

        return filled($account) ? (string) $account : null;
    }

    public function formatBookingPayload(CompanyBooking $booking): array
    {
        return [
            'id' => $booking->id,
            'booking_id' => $booking->booking_id,
            'sub_company' => $booking->sub_company,
            'multi_booking' => $booking->multi_booking,
            'multi_days' => $booking->multi_days,
            'pickup_time' => $booking->pickup_time,
            'pickup_time_type' => $booking->pickup_time_type,
            'is_scheduled' => (bool) $booking->is_scheduled,
            'pre_booking' => $booking->pre_booking,
            'dispatch_released' => (bool) $booking->dispatch_released,
            'dispatch_release_at' => optional($booking->dispatch_release_at)->format('Y-m-d H:i:s'),
            'dispatch_release_mode' => $booking->dispatch_release_mode,
            'dispatch_release_override' => (bool) $booking->dispatch_release_override,
            'reminder_minutes' => $booking->reminder_minutes,
            'booking_date' => $booking->booking_date,
            'booking_type' => $booking->booking_type,
            'pickup_point' => $booking->pickup_point,
            'pickup_location' => $booking->pickup_location,
            'pickup_plot_id' => $booking->pickup_plot_id,
            'destination_point' => $booking->destination_point,
            'destination_location' => $booking->destination_location,
            'destination_plot_id' => $booking->destination_plot_id,
            'via_point' => $booking->via_point,
            'via_location' => $booking->via_location,
            'name' => $booking->name,
            'email' => $booking->email,
            'phone_no' => $booking->phone_no,
            'tel_no' => $booking->tel_no,
            'journey_type' => $booking->journey_type,
            'account' => $booking->account,
            'account_id' => $booking->account,
            'vehicle' => $booking->vehicle,
            'driver' => $booking->driver,
            'pending_driver_id' => $booking->pending_driver_id,
            'passenger' => $booking->passenger,
            'luggage' => $booking->luggage,
            'hand_luggage' => $booking->hand_luggage,
            'special_request' => $booking->special_request,
            'payment_reference' => $booking->payment_reference,
            'booking_system' => $booking->booking_system,
            'bidding_fallback' => (bool) $booking->bidding_fallback,
            'parking_charge' => $booking->parking_charge,
            'waiting_charge' => $booking->waiting_charge,
            'ac_fares' => $booking->ac_fares,
            'return_ac_fares' => $booking->return_ac_fares,
            'ac_parking_charge' => $booking->ac_parking_charge,
            'ac_waiting_charge' => $booking->ac_waiting_charge,
            'extra_charge' => $booking->extra_charge,
            'toll' => $booking->toll,
            'booking_status' => $booking->booking_status,
            'distance' => $booking->distance,
            'booking_amount' => $booking->booking_amount,
            'recommended_amount' => $booking->recommended_amount,
            'offered_amount' => $booking->offered_amount,
            'dispatcher_id' => $booking->dispatcher_id,
            'week' => $booking->week,
            'start_at' => $booking->start_at,
            'end_at' => $booking->end_at,
            'payment_method' => $booking->payment_method,
            'driverDetail' => $booking->driverDetail,
            'vehicleDetail' => $booking->vehicleDetail,
            'subCompanyDetail' => $booking->subCompanyDetail,
            'accountDetail' => $booking->accountDetail,
        ];
    }
}
