<?php

namespace App\Services;

use App\Models\CompanyBooking;
use App\Support\PlotDispatch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class BookingDateClassificationService
{
    private const TERMINAL_STATUSES = ['completed', 'no_show', 'cancelled'];

    private function applyNonTerminalFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner
                ->whereNull('booking_status')
                ->orWhereNotIn('booking_status', self::TERMINAL_STATUSES);
        });
    }

    public function normalizeMultiDays($multiDays): array
    {
        if (is_array($multiDays)) {
            $days = $multiDays;
        } else {
            $decoded = json_decode((string) $multiDays, true);
            if (is_array($decoded)) {
                $days = $decoded;
            } else {
                $days = array_map('trim', explode(',', (string) $multiDays));
            }
        }

        return array_values(array_unique(array_filter(array_map(function ($day) {
            $day = trim((string) $day);
            if ($day === '') {
                return null;
            }

            return ucfirst(strtolower(substr($day, 0, 3)));
        }, $days))));
    }

    public function generateOccurrenceDates(string $startAt, string $endAt, array $weekdays): array
    {
        $dates = [];
        $start = Carbon::parse($startAt)->startOfDay();
        $end = Carbon::parse($endAt)->startOfDay();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (in_array($date->format('D'), $weekdays, true)) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    public function todayDateString(): string
    {
        return Carbon::today()->toDateString();
    }

    public function isToday(string $bookingDate): bool
    {
        return Carbon::parse($bookingDate)->toDateString() === $this->todayDateString();
    }

    public function isFuture(string $bookingDate): bool
    {
        return Carbon::parse($bookingDate)->toDateString() > $this->todayDateString();
    }

    public function applyFilter(Builder $query, ?string $filter): Builder
    {
        if (!$filter) {
            return $query;
        }

        switch ($filter) {
            case 'todays_booking':
                return $query
                    ->whereDate('booking_date', Carbon::today())
                    ->where(function (Builder $inner) {
                        $inner
                            ->whereNull('booking_status')
                            ->orWhereNotIn('booking_status', self::TERMINAL_STATUSES);
                    });
            case 'pre_bookings':
                return $query
                    ->whereDate('booking_date', '>', Carbon::today())
                    ->where(function (Builder $inner) {
                        $inner
                            ->whereNull('booking_status')
                            ->orWhereNotIn('booking_status', self::TERMINAL_STATUSES);
                    });
            case 'completed':
                return $query->where('booking_status', 'completed');
            case 'no_show':
                return $query->where('booking_status', 'no_show');
            case 'cancelled':
                return $query->where('booking_status', 'cancelled');
            case 'recent_jobs':
                return $query->where('updated_at', '>=', Carbon::now()->subDays(7));
            default:
                return $query;
        }
    }

    public function dashboardCounts(?Builder $baseQuery = null): array
    {
        $query = $baseQuery ?? CompanyBooking::query();
        $today = Carbon::today();

        return [
            'todaysBooking' => PlotDispatch::applyTodaysBookingVisibilityFilter(
                (clone $query)->whereDate('booking_date', $today)
            )->count(),
            'preBookings' => $this->applyNonTerminalFilter(
                (clone $query)->whereDate('booking_date', '>', $today)
            )->count(),
            'completed' => (clone $query)->where('booking_status', 'completed')->count(),
            'noShow' => (clone $query)->where('booking_status', 'no_show')->count(),
            'cancelled' => (clone $query)->where('booking_status', 'cancelled')->count(),
            'recentJobs' => (clone $query)->where('updated_at', '>=', Carbon::now()->subDays(7))->count(),
        ];
    }
}
