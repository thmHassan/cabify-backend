<?php

namespace App\Support;

use App\Models\CompanyBooking;
use App\Services\PreBookingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class PlotDispatch
{
    public const ACTIVE_PREFIX = 'PLOT_DISPATCH_ACTIVE|';

    public const EXHAUSTED_ACTION = 'Plot dispatch failed — no driver accepted across primary/backup plots. Available for manual dispatch.';
    public const EXHAUSTED_BIDDING_ACTION = 'Plot dispatch failed — no driver accepted across primary/backup plots. Available for manual dispatch and fixed-fare bidding.';
    public const MISSING_PICKUP_PLOT_ACTION = 'Pickup is outside all service plots. Manual dispatch required.';

    public static function isActiveOffer(?string $dispatcherAction): bool
    {
        return self::isInProgressAction($dispatcherAction);
    }

    public static function isInProgressAction(?string $dispatcherAction): bool
    {
        if (!is_string($dispatcherAction) || $dispatcherAction === '') {
            return false;
        }

        if (str_starts_with($dispatcherAction, self::ACTIVE_PREFIX)) {
            return true;
        }

        $normalized = strtolower($dispatcherAction);

        foreach ([
            'no driver accepted',
            'plot dispatch failed',
            'all plots exhausted',
            'available for manual',
            'manual dispatch required',
            'accepted by driver',
        ] as $excluded) {
            if (str_contains($normalized, $excluded)) {
                return false;
            }
        }

        foreach ([
            'started plot-based dispatch',
            'broadcast to',
            'driver(s) in plot',
            'driver(s) in backup plot',
            'offered to driver',
            'dispatched to primary plot',
            'dispatched to backup plot',
            'plot dispatch',
            'in progress',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function isExhaustedAction(?string $dispatcherAction): bool
    {
        if (!is_string($dispatcherAction) || $dispatcherAction === '') {
            return false;
        }

        $normalized = strtolower($dispatcherAction);

        foreach ([
            'no driver accepted',
            'plot dispatch failed',
            'all plots exhausted',
            'available for manual',
            'manual dispatch required',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function activeAction(string $message): string
    {
        return self::ACTIVE_PREFIX . $message;
    }

    public static function startedAction(int $plotId): string
    {
        return self::activeAction("Started plot-based dispatch for primary plot #{$plotId} — in progress");
    }

    public static function broadcastAction(int $driverCount, int $plotId, bool $isBackup, int $timeoutSeconds): string
    {
        $plotLabel = $isBackup ? 'backup plot' : 'plot';

        return self::activeAction(
            "Broadcast to {$driverCount} driver(s) in {$plotLabel} #{$plotId} — waiting up to {$timeoutSeconds}s"
        );
    }

    public static function singleOfferAction(int $driverId, int $rank, int $plotId, bool $isBackup, int $timeoutSeconds): string
    {
        $plotLabel = $isBackup ? 'backup plot' : 'primary plot';

        return self::activeAction(
            "Offered to driver #{$driverId} rank {$rank} in {$plotLabel} #{$plotId} — waiting up to {$timeoutSeconds}s"
        );
    }

    public static function dispatchedPlotAction(int $plotId, bool $isBackup): string
    {
        $label = $isBackup ? 'backup plot' : 'primary plot';

        return self::activeAction("Dispatched to {$label} #{$plotId} — plot dispatch in progress");
    }

    public static function acceptedAction(int $driverId): string
    {
        return "Plot-based dispatch — accepted by driver #{$driverId}";
    }

    public static function applyTodaysBookingVisibilityFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $visible) {
            $visible
                ->whereNull('booking_status')
                ->orWhereNotIn('booking_status', ['completed', 'no_show', 'cancelled']);
        });
    }

    public static function isFreshAsapAwaitingDispatch(CompanyBooking $booking): bool
    {
        if ($booking->booking_status !== 'pending' || !empty($booking->driver) || !empty($booking->dispatcher_action)) {
            return false;
        }

        $pickupTime = strtolower(trim((string) $booking->pickup_time));
        $pickupTimeType = strtolower(trim((string) ($booking->pickup_time_type ?? '')));

        if ($pickupTime !== 'asap' && $pickupTimeType !== 'asap') {
            return false;
        }

        return !app(PreBookingService::class)->bookingQualifiesAsPreBooking($booking);
    }
}
