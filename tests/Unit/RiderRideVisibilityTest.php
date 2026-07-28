<?php

namespace Tests\Unit;

use App\Http\Controllers\Rider\BookingController;
use App\Models\CompanyBooking;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class RiderRideVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::connection('sqlite')->getPdo();

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('booking_status');
            $table->string('pickup_time')->nullable();
            $table->string('pickup_time_type')->nullable();
            $table->date('booking_date')->nullable();
            $table->string('booking_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('booking_amount')->nullable();
            $table->string('currency')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bookings');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_pending_asap_is_current_and_not_upcoming(): void
    {
        $asapId = $this->insertBooking([
            'pickup_time' => 'asap',
            'pickup_time_type' => 'asap',
        ]);
        $scheduledId = $this->insertBooking([
            'pickup_time' => '14:00:00',
            'pickup_time_type' => 'time',
        ]);

        $this->assertSame([$asapId], $this->currentIds());
        $this->assertSame([$scheduledId], $this->upcomingIds());
    }

    public function test_legacy_asap_marker_is_also_current_and_not_upcoming(): void
    {
        $legacyAsapId = $this->insertBooking([
            'pickup_time' => ' ASAP ',
            'pickup_time_type' => null,
        ]);

        $this->assertSame([$legacyAsapId], $this->currentIds());
        $this->assertSame([], $this->upcomingIds());
    }

    public function test_active_scheduled_ride_remains_current(): void
    {
        $activeId = $this->insertBooking([
            'booking_status' => 'ongoing',
            'pickup_time' => '14:00:00',
            'pickup_time_type' => 'time',
        ]);

        $this->assertSame([$activeId], $this->currentIds());
    }

    public function test_completed_ride_with_pending_payment_blocks_a_new_booking(): void
    {
        $bookingId = $this->insertBooking([
            'booking_status' => 'completed',
            'payment_method' => 'online',
            'payment_status' => 'pending',
            'booking_amount' => '125.50',
            'currency' => 'PKR',
        ]);

        $booking = $this->unpaidCompletedRide();

        $this->assertSame($bookingId, $booking?->id);
        $this->assertSame('125.50', $booking?->booking_amount);
    }

    public function test_paid_and_legacy_null_payment_status_rides_do_not_block_a_new_booking(): void
    {
        $this->insertBooking([
            'booking_status' => 'completed',
            'payment_status' => 'completed',
        ]);
        $this->insertBooking([
            'booking_status' => 'completed',
            'payment_status' => null,
        ]);

        $this->assertNull($this->unpaidCompletedRide());
    }

    private function currentIds(): array
    {
        $query = CompanyBooking::where('user_id', 10);
        $this->invokeFilter('applyRiderCurrentRideFilter', $query);

        return $query->pluck('id')->all();
    }

    private function upcomingIds(): array
    {
        $query = CompanyBooking::where('user_id', 10)->where('booking_status', 'pending');
        $this->invokeFilter('excludeAsapRides', $query);

        return $query->pluck('id')->all();
    }

    private function invokeFilter(string $methodName, $query): void
    {
        $method = (new ReflectionClass(BookingController::class))->getMethod($methodName);
        $method->setAccessible(true);
        $method->invoke(new BookingController(), $query);
    }

    private function unpaidCompletedRide(): ?CompanyBooking
    {
        $method = (new ReflectionClass(BookingController::class))->getMethod('unpaidCompletedRide');
        $method->setAccessible(true);

        return $method->invoke(new BookingController(), 10);
    }

    private function insertBooking(array $overrides): int
    {
        return DB::table('bookings')->insertGetId(array_merge([
            'user_id' => 10,
            'booking_status' => 'pending',
            'pickup_time' => 'asap',
            'pickup_time_type' => 'asap',
            'booking_date' => '2026-07-22',
            'booking_id' => null,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'booking_amount' => '100',
            'currency' => 'PKR',
        ], $overrides));
    }
}
