<?php

namespace App\Services;

use App\Jobs\ReleasePreBookingJob;
use App\Models\CompanyBooking;
use App\Models\CompanySetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PreBookingService
{
    public const DISPATCH_LEAD_MINUTES = 60;
    public const RELEASE_MODE_AUTO_DISPATCH = 'auto_dispatch';
    public const RELEASE_MODE_BIDDING = 'bidding';
    public const RELEASE_MODE_AUTO_THEN_BIDDING = 'auto_then_bidding';
    public const RELEASE_MODE_MANUAL_REVIEW = 'manual_review';

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
        $today = Carbon::now($this->companyTimezone())->toDateString();
        return $query
            ->where(function (Builder $builder) {
                $builder
                    ->where('pickup_time_type', 'time')
                    ->orWhere('is_scheduled', true);
            })
            ->where(function (Builder $builder) {
                $builder
                    ->whereNull('dispatch_released')
                    ->orWhere('dispatch_released', false);
            })
            ->where('booking_status', 'pending')
            ->whereDate('booking_date', '>', $today);
    }

    public function bookingQualifiesAsPreBooking(CompanyBooking $booking): bool
    {
        $isScheduled = $booking->pickup_time_type === 'time' || (bool) $booking->is_scheduled;
        $dispatchReleased = $booking->dispatch_released === true || $booking->dispatch_released === 1;

        if (!$isScheduled || $dispatchReleased) {
            return false;
        }

        if ($booking->booking_status !== 'pending') {
            return false;
        }

        if (!$booking->booking_date || $this->isLegacyAsapPickup($booking->pickup_time)) {
            return false;
        }

        try {
            $timezone = $this->companyTimezone();
            $pickupAt = $this->parseCompanyDateTimeToUtc($booking->booking_date . ' ' . $booking->pickup_time, $timezone);
            $pickupCompanyDate = $pickupAt->copy()->setTimezone($timezone)->toDateString();
            $today = Carbon::now($timezone)->toDateString();

            return $pickupAt->isFuture() && $pickupCompanyDate > $today;
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

        // The sync queue driver executes jobs immediately and ignores delay,
        // which would release future pre-bookings as soon as they are created.
        if ($releaseAt->isFuture() && config('queue.default') === 'sync') {
            return;
        }

        $job = ReleasePreBookingJob::dispatch(
            $booking->id,
            $tenantDatabase,
            $this->resolvePickupSnapshot($booking),
            $this->resolveReleaseSnapshot($booking)
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

        $companySetting = CompanySetting::orderBy('id', 'DESC')->first();
        $settings = CompanySetting::resolveReleaseSettings($companySetting);
        if (!$settings['enabled']) {
            return null;
        }

        if ($this->normalizeReleaseMode($booking->dispatch_release_mode) === self::RELEASE_MODE_MANUAL_REVIEW) {
            return null;
        }

        if ($booking->dispatch_release_at) {
            return $this->parseStoredDateTimeToUtc($booking->dispatch_release_at);
        }

        try {
            $pickupAt = $this->parseCompanyDateTimeToUtc(
                $booking->booking_date . ' ' . $booking->pickup_time,
                $this->companyTimezone($companySetting)
            );
            $leadMinutes = (int) ($settings['lead_minutes'] ?? self::DISPATCH_LEAD_MINUTES);

            return $pickupAt->copy()->subMinutes($leadMinutes);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function applyDispatchReleaseDefaults(CompanyBooking $booking, Request $request): void
    {
        if (!$this->isScheduledBooking($booking)) {
            $booking->dispatch_release_at = null;
            $booking->dispatch_release_mode = null;
            $booking->dispatch_release_override = false;

            return;
        }

        $companySetting = CompanySetting::orderBy('id', 'DESC')->first();
        $settings = CompanySetting::resolveReleaseSettings($companySetting);
        $timezone = $this->companyTimezone($companySetting);
        $releaseEnabled = $settings['enabled'];

        if ($request->has('dispatch_release_enabled')) {
            $releaseEnabled = filter_var($request->input('dispatch_release_enabled'), FILTER_VALIDATE_BOOLEAN);
        } elseif ($request->has('auto_release')) {
            $releaseEnabled = filter_var($request->input('auto_release'), FILTER_VALIDATE_BOOLEAN);
        }

        $mode = $this->normalizeReleaseMode(
            $request->input('dispatch_release_mode', $booking->dispatch_release_mode ?: $settings['mode'])
        );

        if (!$settings['enabled'] || !$releaseEnabled || $mode === self::RELEASE_MODE_MANUAL_REVIEW) {
            $booking->dispatch_release_at = null;
            $booking->dispatch_release_mode = self::RELEASE_MODE_MANUAL_REVIEW;
            $booking->dispatch_release_override = $request->hasAny([
                'dispatch_release_enabled',
                'auto_release',
                'dispatch_release_mode',
                'dispatch_release_at',
            ]);

            return;
        }

        $booking->dispatch_release_mode = $mode;

        if ($request->filled('dispatch_release_at')) {
            $booking->dispatch_release_at = $this->parseCompanyDateTimeToUtc($request->input('dispatch_release_at'), $timezone);
            $booking->dispatch_release_override = true;

            return;
        }

        try {
            $pickupAt = $this->parseCompanyDateTimeToUtc($booking->booking_date . ' ' . $booking->pickup_time, $timezone);
            $booking->dispatch_release_at = $pickupAt->copy()->subMinutes((int) ($settings['lead_minutes'] ?? self::DISPATCH_LEAD_MINUTES));
            $booking->dispatch_release_override = false;
        } catch (\Exception $e) {
            $booking->dispatch_release_at = null;
            $booking->dispatch_release_override = false;
        }
    }

    public function normalizeReleaseMode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        if (in_array($mode, CompanySetting::RELEASE_MODES, true)) {
            return $mode;
        }

        return CompanySetting::DEFAULT_RELEASE_MODE;
    }

    public function releaseModeAllowsAutomaticRelease(?string $mode): bool
    {
        return $this->normalizeReleaseMode($mode) !== self::RELEASE_MODE_MANUAL_REVIEW;
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

    public function resolveReleaseSnapshot(CompanyBooking $booking): ?string
    {
        if (!$booking->dispatch_release_at) {
            return null;
        }

        try {
            return $this->parseStoredDateTimeToUtc($booking->dispatch_release_at)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    public function companyTimezone(?CompanySetting $settings = null): string
    {
        $timezone = trim((string) ($settings?->company_timezone ?? ''));

        if ($timezone === '') {
            $timezone = trim((string) (CompanySetting::orderBy('id', 'DESC')->value('company_timezone') ?? ''));
        }

        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            return config('app.timezone', 'UTC');
        }

        return $timezone;
    }

    public function parseCompanyDateTimeToUtc(string $value, ?string $timezone = null): Carbon
    {
        return Carbon::parse($value, $timezone ?: $this->companyTimezone())->utc();
    }

    public function parseStoredDateTimeToUtc(CarbonInterface|string $value): Carbon
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc();
        }

        return Carbon::parse($value, config('app.timezone', 'UTC'))->utc();
    }

    public function formatStoredDateTimeForCompany(CarbonInterface|string|null $value, string $format = 'Y-m-d H:i:s', ?string $timezone = null): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return $this->parseStoredDateTimeToUtc($value)
                ->setTimezone($timezone ?: $this->companyTimezone())
                ->format($format);
        } catch (\Exception $e) {
            return null;
        }
    }
}
