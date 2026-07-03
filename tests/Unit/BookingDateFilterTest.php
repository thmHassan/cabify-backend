<?php

namespace Tests\Unit;

use App\Models\CompanyBooking;
use App\Services\BookingDateClassificationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingDateFilterTest extends TestCase
{
    private BookingDateClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookingDateClassificationService();
        Carbon::setTestNow(Carbon::parse('2025-06-11 09:00:00'));

        Schema::dropIfExists('bookings');
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->date('booking_date')->nullable();
            $table->string('pickup_time')->nullable();
            $table->string('pickup_time_type')->nullable();
            $table->boolean('is_scheduled')->default(false);
            $table->boolean('dispatch_released')->default(false);
            $table->string('booking_status')->default('pending');
            $table->string('dispatcher_action')->nullable();
            $table->unsignedBigInteger('driver')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bookings');
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function insertBooking(
        string $bookingDate,
        string $status = 'pending',
        ?Carbon $createdAt = null,
        bool $isScheduled = false,
        bool $dispatchReleased = false,
        string $pickupTime = '10:00:00',
        string $pickupTimeType = 'time'
    ): void {
        DB::table('bookings')->insert([
            'booking_date' => $bookingDate,
            'pickup_time' => $pickupTime,
            'pickup_time_type' => $pickupTimeType,
            'is_scheduled' => $isScheduled,
            'dispatch_released' => $dispatchReleased,
            'booking_status' => $status,
            'dispatcher_action' => null,
            'driver' => null,
            'created_at' => ($createdAt ?? now())->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_todays_booking_filter_uses_booking_date_only(): void
    {
        $this->insertBooking('2025-06-11', 'pending', now()->subDays(3));
        $this->insertBooking('2025-06-13', 'pending');

        $results = $this->service
            ->applyFilter(CompanyBooking::query(), 'todays_booking')
            ->pluck('booking_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();

        $this->assertSame(['2025-06-11'], $results);
    }

    public function test_pre_bookings_filter_includes_future_dates_only(): void
    {
        $this->insertBooking('2025-06-11', 'pending', null, true, false, '08:00:00');
        $this->insertBooking('2025-06-11', 'pending', null, true, false, '11:00:00');
        $this->insertBooking('2025-06-13', 'pending', null, true, false, '10:00:00');
        $this->insertBooking('2025-06-16', 'pending', null, true, false, '10:00:00');
        $this->insertBooking('2025-06-13', 'pending', null, true, true, '10:00:00');

        $results = $this->service
            ->applyFilter(CompanyBooking::query(), 'pre_bookings')
            ->orderBy('booking_date')
            ->orderBy('pickup_time')
            ->get()
            ->map(fn ($booking) => Carbon::parse($booking->booking_date)->toDateString() . ' ' . $booking->pickup_time)
            ->all();

        $this->assertSame([
            '2025-06-13 10:00:00',
            '2025-06-16 10:00:00',
        ], $results);
    }

    public function test_dashboard_counts_match_filter_logic(): void
    {
        $this->insertBooking('2025-06-11', 'pending', null, false, false, '08:00:00');
        $this->insertBooking('2025-06-11', 'pending', null, true, false, '11:00:00');
        $this->insertBooking('2025-06-13', 'pending', null, true, false, '10:00:00');
        $this->insertBooking('2025-06-10', 'completed');

        $counts = $this->service->dashboardCounts();

        $this->assertSame(2, $counts['todaysBooking']);
        $this->assertSame(1, $counts['preBookings']);
        $this->assertSame(1, $counts['completed']);
    }
}
