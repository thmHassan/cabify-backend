<?php

namespace App\Services;

use App\Jobs\SendBiddingFixedFareNotificationJob;
use App\Models\CompanyBooking;
use App\Models\CompanyDispatchSystem;
use App\Models\CompanyDriver;
use App\Models\CompanySendNewRide;
use App\Models\CompanySetting;
use App\Models\WalletTransaction;
use App\Support\PlotDispatch;
use App\Support\VehicleDispatchFilter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;

class BookingDispatchService
{
    public function releaseForDispatch(CompanyBooking $booking, string $tenantDatabase, ?string $socketApiBaseUrl = null): void
    {
        if ($booking->dispatch_released) {
            if ($this->shouldRepairReleasedScheduledBooking($booking)) {
                $this->startAutomaticDispatch($booking, $tenantDatabase, $socketApiBaseUrl);
            }

            return;
        }

        if (in_array($booking->booking_status, ['cancelled', 'completed'], true)) {
            return;
        }

        if ($this->isAcceptedScheduledDriverBooking($booking)) {
            $booking->dispatch_released = true;
            $booking->pending_driver_id = null;
            $booking->save();

            return;
        }

        if (!app(PreBookingService::class)->releaseModeAllowsAutomaticRelease($booking->dispatch_release_mode)) {
            $booking->dispatcher_action = $this->withCreatorLog($booking, 'No driver selected - held for manual review.');
            $booking->save();

            return;
        }

        if ($booking->pending_driver_id && !$booking->driver) {
            $booking->driver = $booking->pending_driver_id;
            $booking->pending_driver_id = null;
        }

        $booking->dispatch_released = true;
        $booking->save();

        $hasDriver = !empty($booking->driver);

        if ($hasDriver) {
            $driver = CompanyDriver::find($booking->driver);
            $companySetting = CompanySetting::orderBy('id', 'DESC')->first();

            if ($driver && $companySetting) {
                $this->applyDriverBookingDeductions($driver, $companySetting);
            }

            $this->notifyAssignedDriver($booking, $socketApiBaseUrl);

            return;
        }

        $this->startAutomaticDispatch($booking, $tenantDatabase, $socketApiBaseUrl);
    }

    public function notifyPreBookingCreated(CompanyBooking $booking, string $tenantDatabase, ?string $socketApiBaseUrl = null): void
    {
        Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->internalSecret(),
        ])->timeout(5)->post($this->socketEndpoint($socketApiBaseUrl, 'bookings/broadcast'), [
            'booking_id' => $booking->id,
            'tenantDb' => $tenantDatabase,
            'pre_booking' => true,
        ]);
    }

    public function notifyBookingUpdated(CompanyBooking $booking, string $tenantDatabase, ?string $socketApiBaseUrl = null): void
    {
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->internalSecret(),
            ])->timeout(5)->post($this->socketEndpoint($socketApiBaseUrl, 'bookings/notify-updated'), [
                'booking_id' => $booking->id,
                'tenantDb' => $tenantDatabase,
            ]);
        } catch (\Exception $e) {
            \Log::warning('Booking updated socket call failed', [
                'booking_id' => $booking->id,
                'tenant' => $tenantDatabase,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyImmediateBookingCreated(
        CompanyBooking $booking,
        string $tenantDatabase,
        bool $alwaysBroadcast = false,
        ?string $socketApiBaseUrl = null
    ): void {
        $hasDriver = !empty($booking->driver);
        $dispatchSystem = $this->primaryDispatchSystem();
        $nearestDriverDispatch = !$hasDriver && $dispatchSystem === 'auto_dispatch_nearest_driver';
        $plotDispatch = !$hasDriver && $dispatchSystem === 'auto_dispatch_plot_base';

        if (!$hasDriver && $dispatchSystem === 'bidding') {
            $this->notifyBiddingDrivers($booking, $socketApiBaseUrl);

            return;
        }

        $shouldShowOnManualPanel =
            (!$hasDriver || $alwaysBroadcast)
            && !$nearestDriverDispatch
            && !$plotDispatch
            && !in_array($dispatchSystem, ['bidding', 'bidding_fixed_fare_plot_base'], true);

        if ($shouldShowOnManualPanel) {
            if (VehicleDispatchFilter::bookingRequiresSpecificVehicle($booking)) {
                $this->notifyEligibleDriversForVehicleRestrictedBooking($booking, $socketApiBaseUrl);
            } else {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->internalSecret(),
                ])->timeout(5)->post($this->socketEndpoint($socketApiBaseUrl, 'bookings/broadcast'), [
                    'booking_id' => $booking->id,
                    'tenantDb' => $tenantDatabase,
                ]);
            }
        }

        if (!$hasDriver) {
            $this->startAutomaticDispatch($booking, $tenantDatabase, $socketApiBaseUrl);

            return;
        }

        $this->notifyAssignedDriver($booking, $socketApiBaseUrl);
    }

    private function notifyAssignedDriver(CompanyBooking $booking, ?string $socketApiBaseUrl = null): void
    {
        $booking->loadMissing('userDetail');

        Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->internalSecret(),
        ])->timeout(5)->post($this->socketEndpoint($socketApiBaseUrl, 'send-new-ride'), [
            'drivers' => [$booking->driver],
            'booking' => [
                'id' => $booking->id,
                'booking_id' => $booking->booking_id,
                'pickup_point' => $booking->pickup_point,
                'destination_point' => $booking->destination_point,
                'offered_amount' => $booking->offered_amount,
                'distance' => $booking->distance,
                'user_id' => $booking->user_id,
                'user_name' => $booking->name,
                'user_profile' => $booking->userDetail->profile_image ?? null,
                'pickup_location' => $booking->pickup_location,
                'destination_location' => $booking->destination_location,
                'note' => $booking->note,
                'pickup_time' => $booking->pickup_time,
                'booking_date' => $booking->booking_date,
            ],
        ]);

        $sendRide = new CompanySendNewRide;
        $sendRide->booking_id = $booking->id;
        $sendRide->driver_id = $booking->driver;
        $sendRide->save();
    }

    private function bookingSocketPayload(CompanyBooking $booking, array $extra = []): array
    {
        $booking->loadMissing('userDetail');

        return array_merge([
            'id' => $booking->id,
            'booking_id' => $booking->booking_id,
            'pickup_point' => $booking->pickup_point,
            'destination_point' => $booking->destination_point,
            'offered_amount' => $booking->offered_amount,
            'distance' => $booking->distance,
            'user_id' => $booking->user_id,
            'user_name' => $booking->name,
            'name' => $booking->name,
            'user_profile' => $booking->userDetail->profile_image ?? null,
            'pickup_location' => $booking->pickup_location,
            'destination_location' => $booking->destination_location,
            'note' => $booking->note,
            'pickup_time' => $booking->pickup_time,
            'booking_date' => $booking->booking_date,
        ], $extra);
    }

    private function startAutomaticDispatch(CompanyBooking $booking, string $tenantDatabase, ?string $socketApiBaseUrl = null): void
    {
        $releaseMode = $booking->dispatch_release_mode
            ? app(PreBookingService::class)->normalizeReleaseMode($booking->dispatch_release_mode)
            : null;

        $dispatchSystem = $this->primaryDispatchSystem();
        if (!$dispatchSystem || $dispatchSystem === 'manual_dispatch_only') {
            $booking->dispatcher_action = $this->withCreatorLog($booking, 'No driver selected - available for manual dispatch.');
            $booking->save();
            return;
        }

        if ($releaseMode === PreBookingService::RELEASE_MODE_BIDDING || $dispatchSystem === 'bidding') {
            $booking->dispatcher_action = $this->withCreatorLog($booking, 'No driver selected - released to bidding.');
            $booking->save();
            $this->notifyBiddingDrivers($booking, $socketApiBaseUrl);

            return;
        }

        if ($releaseMode === PreBookingService::RELEASE_MODE_AUTO_THEN_BIDDING) {
            $booking->booking_system = 'auto_dispatch';
            $booking->bidding_fallback = true;
            $booking->save();
        }

        if ($dispatchSystem === 'auto_dispatch_plot_base') {
            $booking->dispatcher_action = $this->withCreatorLog($booking, 'No driver selected - released to auto dispatch.');
            $booking->save();

            Http::withHeaders([
                'database' => $tenantDatabase,
                'Accept' => 'application/json',
            ])->timeout(5)->post($this->socketEndpoint($socketApiBaseUrl, 'bookings/' . $booking->id . '/start-auto-dispatch'));
        } elseif ($dispatchSystem === 'bidding_fixed_fare_plot_base') {
            $booking->dispatcher_action = $this->withCreatorLog($booking, 'No driver selected - released to fixed fare bidding.');
            $booking->save();

            SendBiddingFixedFareNotificationJob::dispatch($booking->id, null, 0, $tenantDatabase);
        } elseif ($dispatchSystem === 'auto_dispatch_nearest_driver') {
            $booking->dispatcher_action = $this->withCreatorLog($booking, 'No driver selected - released to nearest driver dispatch.');
            $booking->save();

            Http::withHeaders([
                'database' => $tenantDatabase,
                'Accept' => 'application/json',
            ])->timeout(5)->post($this->socketEndpoint($socketApiBaseUrl, 'bookings/' . $booking->id . '/start-nearest-dispatch'));
        } else {
            $booking->dispatcher_action = $this->withCreatorLog($booking, 'No driver selected - available for manual dispatch.');
            $booking->save();
        }
    }

    private function shouldRepairReleasedScheduledBooking(CompanyBooking $booking): bool
    {
        if (!$booking->dispatch_released || !$booking->is_scheduled || $booking->booking_status !== 'pending') {
            return false;
        }

        if ($booking->driver || $booking->pending_driver_id) {
            return false;
        }

        if (!app(PreBookingService::class)->releaseModeAllowsAutomaticRelease($booking->dispatch_release_mode)) {
            return false;
        }

        if (!$booking->dispatch_release_at || $this->dispatchReleaseAtIsFuture($booking->dispatch_release_at)) {
            return false;
        }

        $action = strtolower((string) $booking->dispatcher_action);

        return str_contains($action, 'scheduled for auto release');
    }

    private function isAcceptedScheduledDriverBooking(CompanyBooking $booking): bool
    {
        if ($booking->booking_status !== 'pending' || !$booking->driver) {
            return false;
        }

        $isScheduled = $booking->is_scheduled || $booking->pickup_time_type === 'time';
        if (!$isScheduled) {
            return false;
        }

        return str_contains(strtolower((string) $booking->dispatcher_action), 'accepted by driver');
    }

    private function dispatchReleaseAtIsFuture(CarbonInterface|string $releaseAt): bool
    {
        try {
            return app(PreBookingService::class)
                ->parseStoredDateTimeToUtc($releaseAt)
                ->isFuture();
        } catch (\Exception $e) {
            return true;
        }
    }

    private function withCreatorLog(CompanyBooking $booking, string $message): string
    {
        $current = trim((string) $booking->dispatcher_action);

        if (preg_match('/^(Created by [^.]+\\.)/i', $current, $matches)) {
            return trim($matches[1] . ' ' . $message);
        }

        return $message;
    }

    private function notifyEligibleDriversForVehicleRestrictedBooking(CompanyBooking $booking, ?string $socketApiBaseUrl = null): void
    {
        $booking->loadMissing('userDetail');

        $driverIds = CompanyDriver::query()
            ->where('driving_status', 'idle')
            ->where('online_status', 'online')
            ->when(
                VehicleDispatchFilter::bookingRequiresSpecificVehicle($booking),
                fn ($query) => VehicleDispatchFilter::scopeDriversForBooking($query, $booking)
            )
            ->pluck('id');

        foreach ($driverIds as $driverId) {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->internalSecret(),
            ])->timeout(5)->post($this->socketEndpoint($socketApiBaseUrl, 'send-new-ride'), [
                'drivers' => [$driverId],
                'booking' => [
                    'id' => $booking->id,
                    'booking_id' => $booking->booking_id,
                    'pickup_point' => $booking->pickup_point,
                    'destination_point' => $booking->destination_point,
                    'offered_amount' => $booking->offered_amount,
                    'distance' => $booking->distance,
                    'user_id' => $booking->user_id,
                    'user_name' => $booking->name,
                    'user_profile' => $booking->userDetail->profile_image ?? null,
                    'pickup_location' => $booking->pickup_location,
                    'destination_location' => $booking->destination_location,
                    'note' => $booking->note,
                    'pickup_time' => $booking->pickup_time,
                    'booking_date' => $booking->booking_date,
                ],
            ]);

            $sendRide = new CompanySendNewRide;
            $sendRide->booking_id = $booking->id;
            $sendRide->driver_id = $driverId;
            $sendRide->save();
        }
    }

    private function notifyBiddingDrivers(CompanyBooking $booking, ?string $socketApiBaseUrl = null): void
    {
        $driverIds = CompanyDriver::query()
            ->where('status', 'accepted')
            ->where('driving_status', 'idle')
            ->where('online_status', 'online')
            ->when(
                VehicleDispatchFilter::bookingRequiresSpecificVehicle($booking),
                fn ($query) => VehicleDispatchFilter::scopeDriversForBooking($query, $booking)
            )
            ->pluck('id');

        $payload = $this->bookingSocketPayload($booking, [
            'fixed_fare' => true,
            'assignment_type' => 'fixed_fare_bidding',
        ]);

        foreach ($driverIds as $driverId) {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->internalSecret(),
            ])->timeout(5)->post($this->socketEndpoint($socketApiBaseUrl, 'send-new-ride'), [
                'drivers' => [$driverId],
                'booking' => $payload,
            ]);

            $sendRide = new CompanySendNewRide;
            $sendRide->booking_id = $booking->id;
            $sendRide->driver_id = $driverId;
            $sendRide->save();
        }
    }

    private function socketEndpoint(?string $socketApiBaseUrl, string $path): string
    {
        $baseUrl = $socketApiBaseUrl
            ? rtrim($socketApiBaseUrl, '/')
            : SocketApiUrlResolver::resolve();

        return $baseUrl . '/' . ltrim($path, '/');
    }

    private function internalSecret(): ?string
    {
        return config('services.node_socket.internal_secret');
    }

    public function isNearestDriverDispatchEnabled(): bool
    {
        return $this->primaryDispatchSystem() === 'auto_dispatch_nearest_driver';
    }

    public function isPlotDispatchEnabled(): bool
    {
        return $this->primaryDispatchSystem() === 'auto_dispatch_plot_base';
    }

    private function primaryDispatchSystem(): ?string
    {
        return CompanyDispatchSystem::query()
            ->select('dispatch_system')
            ->where('status', 'enable')
            ->groupBy('dispatch_system')
            ->orderByRaw('MIN(priority) ASC')
            ->value('dispatch_system');
    }

    private function applyDriverBookingDeductions(CompanyDriver $driver, CompanySetting $companySetting): void
    {
        if ($companySetting->package_type === 'ride_count_price') {
            $driver->ride_count_price -= 1;
            $driver->save();
        }

        if ($companySetting->package_type === 'per_ride_commission_topup') {
            $checkAmount = $companySetting->package_amount;
            $driver->wallet_balance -= $checkAmount;
            $driver->save();

            $wallet = new WalletTransaction;
            $wallet->user_type = 'driver';
            $wallet->user_id = $driver->id;
            $wallet->type = 'deduct';
            $wallet->amount = $checkAmount;
            $wallet->comment = 'Per ride booking deduction';
            $wallet->save();
        }
    }
}
