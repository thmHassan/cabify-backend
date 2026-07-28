<?php

namespace App\Services;

use App\Models\CompanyBooking;
use App\Models\CompanyDriver;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class CashRideCommissionService
{
    public function deduct(CompanyBooking $booking): array
    {
        return DB::transaction(function () use ($booking) {
            $lockedBooking = CompanyBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($this->paymentChannel($lockedBooking) !== 'cash') {
                return $this->result($lockedBooking, false, 'not_cash');
            }

            $lockedBooking = app(RideCommissionSnapshotService::class)->snapshot($lockedBooking);
            $commission = round(max((float) ($lockedBooking->commission_amount ?? 0), 0), 2);

            if ($commission <= 0 || empty($lockedBooking->driver)) {
                return $this->result($lockedBooking, false, 'no_commission');
            }

            if ($lockedBooking->commission_wallet_debited_at) {
                $this->markReservationSettled($lockedBooking);
                return $this->result($lockedBooking, false, 'already_deducted');
            }

            $reference = (string) $lockedBooking->id;
            $existing = WalletTransaction::where('payment_provider', 'cash_ride_commission')
                ->where('payment_reference', $reference)
                ->first();

            if ($existing) {
                $lockedBooking->commission_wallet_transaction_id = $existing->id;
                $lockedBooking->commission_wallet_debited_at = $existing->created_at ?? now();
                $lockedBooking->commission_reservation_status = 'settled';
                $lockedBooking->saveQuietly();

                return $this->result($lockedBooking, false, 'already_deducted');
            }

            $driver = CompanyDriver::whereKey($lockedBooking->driver)->lockForUpdate()->firstOrFail();
            $balanceBefore = round((float) ($driver->wallet_balance ?? 0), 2);
            $balanceAfter = round($balanceBefore - $commission, 2);

            $driver->wallet_balance = $balanceAfter;
            $driver->saveQuietly();

            $transaction = new WalletTransaction();
            $transaction->user_type = 'driver';
            $transaction->user_id = $driver->id;
            $transaction->type = 'deduct';
            $transaction->amount = $commission;
            $transaction->comment = 'Cash ride commission';
            $transaction->payment_provider = 'cash_ride_commission';
            $transaction->payment_reference = $reference;
            $transaction->save();

            $lockedBooking->commission_wallet_transaction_id = $transaction->id;
            $lockedBooking->commission_wallet_debited_at = now();
            $lockedBooking->commission_reservation_status = $lockedBooking->commission_reservation_status === 'reserved'
                ? 'settled'
                : $lockedBooking->commission_reservation_status;
            $lockedBooking->saveQuietly();

            return $this->result($lockedBooking, true, 'deducted', $balanceBefore, $balanceAfter);
        });
    }

    private function paymentChannel(CompanyBooking $booking): string
    {
        $method = strtolower(trim((string) ($booking->payment_method ?? 'cash')));

        return in_array($method, ['online', 'stripe', 'card'], true) ? 'online' : 'cash';
    }

    private function result(
        CompanyBooking $booking,
        bool $deducted,
        string $status,
        ?float $balanceBefore = null,
        ?float $balanceAfter = null
    ): array {
        return [
            'deducted' => $deducted,
            'status' => $status,
            'commission_amount' => round((float) ($booking->commission_amount ?? 0), 2),
            'reserved_commission' => round((float) ($booking->commission_reserved_amount ?? 0), 2),
            'reservation_status' => $booking->commission_reservation_status,
            'wallet_balance_before' => $balanceBefore,
            'wallet_balance' => $balanceAfter,
        ];
    }

    private function markReservationSettled(CompanyBooking $booking): void
    {
        if ($booking->commission_reservation_status === 'reserved') {
            $booking->commission_reservation_status = 'settled';
            $booking->saveQuietly();
        }
    }
}
