<?php

namespace Tests\Unit;

use App\Services\BookingDateClassificationService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BookingDateClassificationServiceTest extends TestCase
{
    private BookingDateClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookingDateClassificationService();
        Carbon::setTestNow(Carbon::parse('2025-06-10 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_normalize_multi_days_from_array(): void
    {
        $days = $this->service->normalizeMultiDays(['Mon', 'wed', 'FRI']);

        $this->assertSame(['Mon', 'Wed', 'Fri'], $days);
    }

    public function test_normalize_multi_days_from_comma_string(): void
    {
        $days = $this->service->normalizeMultiDays('Mon, Tue, Wed');

        $this->assertSame(['Mon', 'Tue', 'Wed'], $days);
    }

    public function test_generate_occurrence_dates_for_mon_wed_fri_range(): void
    {
        $dates = $this->service->generateOccurrenceDates(
            '2025-06-10',
            '2025-06-20',
            ['Mon', 'Wed', 'Fri']
        );

        $this->assertSame([
            '2025-06-11',
            '2025-06-13',
            '2025-06-16',
            '2025-06-18',
            '2025-06-20',
        ], $dates);
    }

    public function test_generate_occurrence_dates_without_today_match(): void
    {
        $dates = $this->service->generateOccurrenceDates(
            '2025-06-10',
            '2025-06-20',
            ['Mon', 'Fri']
        );

        $this->assertSame([
            '2025-06-13',
            '2025-06-16',
            '2025-06-20',
        ], $dates);

        foreach ($dates as $date) {
            $this->assertTrue($this->service->isFuture($date));
        }
    }

    public function test_generate_occurrence_dates_respects_range_boundaries(): void
    {
        $dates = $this->service->generateOccurrenceDates(
            '2025-06-11',
            '2025-06-11',
            ['Wed']
        );

        $this->assertSame(['2025-06-11'], $dates);
    }

    public function test_classifies_today_and_future_dates(): void
    {
        $this->assertTrue($this->service->isToday('2025-06-10'));
        $this->assertFalse($this->service->isFuture('2025-06-10'));
        $this->assertTrue($this->service->isFuture('2025-06-11'));
    }

    public function test_multi_booking_splits_today_and_future_when_today_is_selected_weekday(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-06-11 09:00:00'));

        $dates = $this->service->generateOccurrenceDates(
            '2025-06-10',
            '2025-06-20',
            ['Mon', 'Wed', 'Fri']
        );

        $todayMatches = array_values(array_filter($dates, fn ($date) => $this->service->isToday($date)));
        $futureMatches = array_values(array_filter($dates, fn ($date) => $this->service->isFuture($date)));

        $this->assertSame(['2025-06-11'], $todayMatches);
        $this->assertSame([
            '2025-06-13',
            '2025-06-16',
            '2025-06-18',
            '2025-06-20',
        ], $futureMatches);
    }
}
