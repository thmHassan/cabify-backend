<?php

namespace App\Services;

use App\Models\CompanyBooking;
use App\Models\CompanyDriver;
use Illuminate\Support\Facades\DB;

class CashRideCommissionReservationService
{
    private const TERMINAL_STATUSES = [
        'cancelled', 'canceled', 'completed', 'no_show', 'no-show',
    ];

    public function reserve(CompanyBooking $booking, int $driverId, ?float $fare = null): array
    {
        return DB::transaction(function () use ($booking, $driverId, $fare) {
            $lockedBooking = CompanyBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($this->paymentChannel($lockedBooking) !== 'cash') {
                return $this->result(true, false, 'not_required', 0, null, null, 0);
            }

            if (
                $lockedBooking->commission_reservation_status === 'reserved'
                && (int) $lockedBooking->commission_reservation_driver_id === $driverId
            ) {
                $driver = CompanyDriver::whereKey($driverId)->lockForUpdate()->firstOrFail();
                $balance = round((float) ($driver->wallet_balance ?? 0), 2);
                $reservedElsewhere = $this->reservedForDriver($driverId, (int) $lockedBooking->id);

                return $this->result(
                    true,
                    false,
                    'already_reserved',
                    (float) $lockedBooking->commission_reserved_amount,
                    $balance,
                    round($balance - $reservedElsewhere - (float) $lockedBooking->commission_reserved_amount, 2),
                    0
                );
            }

            $driver = CompanyDriver::whereKey($driverId)->lockForUpdate()->firstOrFail();
            $quote = app(RideCommissionSnapshotService::class)->quote($lockedBooking, $fare, $driverId);
            $required = round((float) $quote['commission_amount'], 2);
            $balance = round((float) ($driver->wallet_balance ?? 0), 2);
            $reservedElsewhere = $this->reservedForDriver($driverId, (int) $lockedBooking->id);
            $available = round($balance - $reservedElsewhere, 2);

            if ($required > $available) {
                return $this->result(
                    false,
                    false,
                    'insufficient_balance',
                    $required,
                    $balance,
                    $available,
                    round($required - $available, 2)
                );
            }

            $lockedBooking->commission_reservation_driver_id = $driverId;
            $lockedBooking->commission_reserved_package_id = $quote['driver_package_id'];
            $lockedBooking->commission_reserved_type = $quote['commission_type'];
            $lockedBooking->commission_reserved_rate = $quote['commission_rate'];
            $lockedBooking->commission_reserved_fixed_amount = $quote['commission_fixed_amount'];
            $lockedBooking->commission_reserved_amount = $required;
            $lockedBooking->commission_reservation_status = 'reserved';
            $lockedBooking->commission_reserved_at = now();
            $lockedBooking->commission_reservation_released_at = null;
            $lockedBooking->saveQuietly();

            return $this->result(
                true,
                true,
                'reserved',
                $required,
                $balance,
                round($available - $required, 2),
                0
            );
        });
    }

    public function release(CompanyBooking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            $lockedBooking = CompanyBooking::whereKey($booking->id)->lockForUpdate()->first();

            if (!$lockedBooking || $lockedBooking->commission_reservation_status !== 'reserved') {
                return false;
            }

            $lockedBooking->commission_reservation_status = 'released';
            $lockedBooking->commission_reservation_released_at = now();
            $lockedBooking->saveQuietly();

            return true;
        });
    }

    public function failure(array $reservation): array
    {
        return [
            'error' => 1,
            'code' => 'INSUFFICIENT_COMMISSION_BALANCE',
            'message' => 'Your wallet balance is less than the commission amount. Please recharge your wallet before accepting this ride.',
            'data' => [
                'wallet_balance' => $reservation['wallet_balance'],
                'available_wallet_balance' => $reservation['available_wallet_balance'],
                'required_commission' => $reservation['required_commission'],
                'recharge_amount' => $reservation['recharge_amount'],
            ],
        ];
    }

    private function reservedForDriver(int $driverId, int $excludeBookingId): float
    {
        return round((float) CompanyBooking::where('commission_reservation_driver_id', $driverId)
            ->where('commission_reservation_status', 'reserved')
            ->where('id', '!=', $excludeBookingId)
            ->whereNotIn('booking_status', self::TERMINAL_STATUSES)
            ->sum('commission_reserved_amount'), 2);
    }

    private function paymentChannel(CompanyBooking $booking): string
    {
        $method = strtolower(trim((string) ($booking->payment_method ?? 'cash')));

        return in_array($method, ['online', 'stripe', 'card'], true) ? 'online' : 'cash';
    }

    private function result(
        bool $allowed,
        bool $created,
        string $status,
        float $required,
        ?float $balance,
        ?float $available,
        float $recharge
    ): array {
        return [
            'allowed' => $allowed,
            'created' => $created,
            'status' => $status,
            'required_commission' => round($required, 2),
            'wallet_balance' => $balance,
            'available_wallet_balance' => $available,
            'recharge_amount' => round(max($recharge, 0), 2),
        ];
    }
}
