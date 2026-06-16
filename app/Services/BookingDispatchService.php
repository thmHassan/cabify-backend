<?php

namespace App\Services;

use App\Jobs\SendBiddingFixedFareNotificationJob;
use App\Models\CompanyBooking;
use App\Models\CompanyDispatchSystem;
use App\Models\CompanyDriver;
use App\Models\CompanySendNewRide;
use App\Models\CompanySetting;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Http;

class BookingDispatchService
{
    public function releaseForDispatch(CompanyBooking $booking, string $tenantDatabase): void
    {
        if ($booking->dispatch_released) {
            return;
        }

        if (in_array($booking->booking_status, ['cancelled', 'completed'], true)) {
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

            $this->notifyAssignedDriver($booking);

            return;
        }

        $this->startAutomaticDispatch($booking, $tenantDatabase);
    }

    public function notifyPreBookingCreated(CompanyBooking $booking, string $tenantDatabase): void
    {
        Http::withHeaders([
            'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
        ])->timeout(5)->post(env('NODE_SOCKET_URL') . '/bookings/broadcast', [
            'booking_id' => $booking->id,
            'tenantDb' => $tenantDatabase,
            'pre_booking' => true,
        ]);
    }

    public function notifyImmediateBookingCreated(CompanyBooking $booking, string $tenantDatabase, bool $alwaysBroadcast = false): void
    {
        $hasDriver = !empty($booking->driver);

        if (!$hasDriver || $alwaysBroadcast) {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->timeout(5)->post(env('NODE_SOCKET_URL') . '/bookings/broadcast', [
                'booking_id' => $booking->id,
                'tenantDb' => $tenantDatabase,
            ]);
        }

        if (!$hasDriver) {
            $this->startAutomaticDispatch($booking, $tenantDatabase);

            return;
        }

        $this->notifyAssignedDriver($booking);
    }

    private function notifyAssignedDriver(CompanyBooking $booking): void
    {
        $booking->loadMissing('userDetail');

        Http::withHeaders([
            'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
        ])->timeout(5)->post(env('NODE_SOCKET_URL') . '/send-new-ride', [
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

    private function startAutomaticDispatch(CompanyBooking $booking, string $tenantDatabase): void
    {
        $dispatchSystems = CompanyDispatchSystem::where('status', 'enable')->orderBy('priority', 'ASC')->get();
        if ($dispatchSystems->isEmpty()) {
            return;
        }

        $dispatchSystem = $dispatchSystems->first()->dispatch_system;

        if ($dispatchSystem === 'auto_dispatch_plot_base') {
            Http::withHeaders([
                'database' => $tenantDatabase,
                'Accept' => 'application/json',
            ])->timeout(5)->post(env('NODE_SOCKET_URL') . '/bookings/' . $booking->id . '/start-auto-dispatch');
        } elseif ($dispatchSystem === 'bidding_fixed_fare_plot_base') {
            SendBiddingFixedFareNotificationJob::dispatch($booking->id, null, 0, $tenantDatabase);
        } elseif ($dispatchSystem === 'auto_dispatch_nearest_driver') {
            Http::withHeaders([
                'database' => $tenantDatabase,
                'Accept' => 'application/json',
            ])->timeout(5)->post(env('NODE_SOCKET_URL') . '/bookings/' . $booking->id . '/start-nearest-dispatch');
        }
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
