<?php

namespace App\Services;

use App\Models\CompanyBooking;
use App\Models\CompanySetting;
use App\Models\DriverPackage;
use App\Models\FinanceSetting;
use Carbon\Carbon;

class RideCommissionSnapshotService
{
    public function snapshot(CompanyBooking $booking, ?float $fare = null): CompanyBooking
    {
        if ($booking->commission_snapshot_at || empty($booking->driver)) {
            return $booking;
        }

        $fare ??= $this->effectiveFare($booking);
        $quote = $booking->commission_reservation_status === 'reserved'
            ? $this->quoteFromReservation($booking, $fare)
            : $this->quote($booking, $fare);

        $booking->driver_package_id = $quote['driver_package_id'];
        $booking->commission_type = $quote['commission_type'];
        $booking->commission_rate = $quote['commission_rate'];
        $booking->commission_amount = $quote['commission_amount'];
        $booking->driver_net_amount = $quote['driver_net_amount'];
        $booking->commission_snapshot_at = now();
        $booking->saveQuietly();

        return $booking;
    }

    public function quote(CompanyBooking $booking, ?float $fare = null, ?int $driverId = null): array
    {
        $fare ??= $this->effectiveFare($booking);
        $package = $this->activeCommissionPackage($booking, $driverId);
        [$type, $rate, $commission, $fixed] = $this->resolveCommission($package, $fare);

        return [
            'driver_package_id' => $package?->id,
            'commission_type' => $type,
            'commission_rate' => $rate,
            'commission_fixed_amount' => $fixed,
            'commission_amount' => $commission,
            'driver_net_amount' => round(max($fare - $commission, 0), 2),
        ];
    }

    private function activeCommissionPackage(CompanyBooking $booking, ?int $driverId = null): ?DriverPackage
    {
        $rideDate = $booking->driver_dropoff_time
            ? Carbon::parse($booking->driver_dropoff_time)->toDateString()
            : ($booking->booking_date ?: now()->toDateString());

        return DriverPackage::where('driver_id', $driverId ?? $booking->driver)
            ->where(function ($query) {
                $query->whereNotNull('commission_value')
                    ->orWhereNotNull('commission_per');
            })
            ->where(function ($query) use ($rideDate) {
                $query->whereNull('start_date')->orWhere('start_date', '<=', $rideDate);
            })
            ->where(function ($query) use ($rideDate) {
                $query->whereNull('expire_date')->orWhere('expire_date', '>=', $rideDate);
            })
            ->orderByDesc('id')
            ->first();
    }

    private function resolveCommission(?DriverPackage $package, float $fare): array
    {
        if ($package && $package->commission_value !== null) {
            $type = $package->commission_type === 'fixed' ? 'fixed' : 'percentage';
            $rate = max((float) $package->commission_value, 0);
            $amount = $type === 'fixed' ? $rate : ($fare * min($rate, 100) / 100);

            return [$type, $rate, round(min($amount, $fare), 2), $type === 'fixed' ? $rate : 0.0];
        }

        if ($package && $package->commission_per !== null) {
            $rate = max(min((float) $package->commission_per, 100), 0);

            return ['percentage', $rate, round($fare * $rate / 100, 2), 0.0];
        }

        $companyRate = CompanySetting::orderByDesc('id')->value('package_percentage');
        if ($companyRate !== null && (float) $companyRate > 0) {
            $rate = max(min((float) $companyRate, 100), 0);

            return ['percentage', $rate, round($fare * $rate / 100, 2), 0.0];
        }

        $settings = FinanceSetting::firstOrCreate([], [
            'account_driver_payout_timing' => 'after_account_collection',
            'cash_driver_collection_policy' => 'driver_owes_commission',
            'online_driver_payout_policy' => 'company_owes_driver_net',
            'default_driver_commission_percent' => 0,
            'default_driver_commission_fixed' => 0,
            'stripe_fee_policy' => 'company_cost',
            'statement_prefix' => 'STMT',
            'settlement_prefix' => 'SETTLE',
        ]);
        $percent = max(min((float) $settings->default_driver_commission_percent, 100), 0);
        $fixed = max((float) $settings->default_driver_commission_fixed, 0);
        $amount = min(($fare * $percent / 100) + $fixed, $fare);

        return [$fixed > 0 ? 'percentage_plus_fixed' : 'percentage', $percent, round($amount, 2), $fixed];
    }

    private function quoteFromReservation(CompanyBooking $booking, float $fare): array
    {
        $type = (string) ($booking->commission_reserved_type ?: 'percentage');
        $rate = max((float) ($booking->commission_reserved_rate ?? 0), 0);
        $fixed = max((float) ($booking->commission_reserved_fixed_amount ?? 0), 0);

        if ($type === 'fixed') {
            $commission = min($fixed > 0 ? $fixed : $rate, $fare);
        } else {
            $commission = min(($fare * min($rate, 100) / 100) + $fixed, $fare);
        }

        $commission = round($commission, 2);

        return [
            'driver_package_id' => $booking->commission_reserved_package_id,
            'commission_type' => $type,
            'commission_rate' => $rate,
            'commission_fixed_amount' => $fixed,
            'commission_amount' => $commission,
            'driver_net_amount' => round(max($fare - $commission, 0), 2),
        ];
    }

    private function effectiveFare(CompanyBooking $booking): float
    {
        foreach (['booking_amount', 'offered_amount', 'recommended_amount'] as $field) {
            if ($booking->{$field} !== null && is_numeric($booking->{$field})) {
                return max((float) $booking->{$field}, 0);
            }
        }

        return 0;
    }
}
