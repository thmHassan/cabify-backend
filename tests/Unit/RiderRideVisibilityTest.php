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

    private function insertBooking(array $overrides): int
    {
        return DB::table('bookings')->insertGetId(array_merge([
            'user_id' => 10,
            'booking_status' => 'pending',
            'pickup_time' => 'asap',
            'pickup_time_type' => 'asap',
            'booking_date' => '2026-07-22',
        ], $overrides));
    }
}
