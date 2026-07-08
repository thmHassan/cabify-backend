<?php

namespace Tests\Unit;

use App\Http\Controllers\Driver\BookingController;
use App\Models\CompanyDriver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BiddingRideVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::connection('sqlite')->getPdo();

        $this->createTables();
        Config::set('services.node_socket.url', 'http://socket.test');
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach ([
            'send_new_rides',
            'bids',
            'bookings',
            'tokens',
            'ratings',
            'users',
            'drivers',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_rejected_or_previously_notified_driver_still_sees_eligible_fallback_bidding_job(): void
    {
        $driverId = DB::table('drivers')->insertGetId([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'assigned_vehicle' => '4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driver = CompanyDriver::findOrFail($driverId);
        Auth::guard('driver')->setUser($driver);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Customer One',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eligibleBookingId = DB::table('bookings')->insertGetId([
            'user_id' => $userId,
            'driver' => null,
            'vehicle' => '4',
            'pickup_time' => 'asap',
            'pickup_time_type' => 'asap',
            'booking_status' => 'pending',
            'booking_date' => now()->toDateString(),
            'distance' => 2500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bookings')->insert([
            'user_id' => $userId,
            'driver' => null,
            'vehicle' => '9',
            'pickup_time' => 'asap',
            'pickup_time_type' => 'asap',
            'booking_status' => 'pending',
            'booking_date' => now()->toDateString(),
            'distance' => 5000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('send_new_rides')->insert([
            'booking_id' => (string) $eligibleBookingId,
            'driver_id' => (string) $driverId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bids')->insert([
            'booking_id' => $eligibleBookingId,
            'driver_id' => $driverId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = (new BookingController())->listRideForBidding(Request::create('/api/driver/list-ride-for-bidding'));
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['success']);
        $this->assertCount(1, $payload['list']);
        $this->assertSame($eligibleBookingId, $payload['list'][0]['id']);
        $this->assertTrue($payload['list'][0]['placed']);
        $this->assertSame(2.5, $payload['list'][0]['distance_value']);
        $this->assertSame('km', $payload['list'][0]['distance_unit']);
    }

    public function test_display_distance_is_not_double_converted_when_booking_stores_display_value(): void
    {
        $driverId = DB::table('drivers')->insertGetId([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'assigned_vehicle' => '4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driver = CompanyDriver::findOrFail($driverId);
        Auth::guard('driver')->setUser($driver);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Customer One',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bookingId = DB::table('bookings')->insertGetId([
            'user_id' => $userId,
            'driver' => null,
            'vehicle' => '4',
            'pickup_time' => 'asap',
            'pickup_time_type' => 'asap',
            'booking_status' => 'pending',
            'booking_date' => now()->toDateString(),
            'distance' => 2.97,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = (new BookingController())->listRideForBidding(Request::create('/api/driver/list-ride-for-bidding'));
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['success']);
        $this->assertCount(1, $payload['list']);
        $this->assertSame($bookingId, $payload['list'][0]['id']);
        $this->assertSame(2.97, $payload['list'][0]['distance_value']);
        $this->assertSame('km', $payload['list'][0]['distance_unit']);
    }

    public function test_immediate_booking_with_real_pickup_time_still_appears_for_fallback_bidding(): void
    {
        $driverId = DB::table('drivers')->insertGetId([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'assigned_vehicle' => '4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driver = CompanyDriver::findOrFail($driverId);
        Auth::guard('driver')->setUser($driver);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Customer One',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bookingId = DB::table('bookings')->insertGetId([
            'user_id' => $userId,
            'driver' => null,
            'vehicle' => '4',
            'pickup_time' => now()->format('H:i:s'),
            'pickup_time_type' => 'asap',
            'booking_status' => 'pending',
            'booking_date' => now()->toDateString(),
            'distance' => 2.15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = (new BookingController())->listRideForBidding(Request::create('/api/driver/list-ride-for-bidding'));
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['success']);
        $this->assertCount(1, $payload['list']);
        $this->assertSame($bookingId, $payload['list'][0]['id']);
    }

    public function test_manual_assignment_expiry_uses_manual_assignment_socket_endpoint(): void
    {
        $driverId = $this->createAuthenticatedDriver();
        $userId = $this->createUser();
        $bookingId = DB::table('bookings')->insertGetId([
            'user_id' => $userId,
            'driver' => $driverId,
            'pending_driver_id' => null,
            'vehicle' => '4',
            'pickup_time' => 'asap',
            'pickup_time_type' => 'asap',
            'booking_status' => 'pending',
            'booking_date' => now()->toDateString(),
            'dispatcher_action' => 'Created by Dispatcher. Driver selected - dispatching now.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'http://socket.test/bookings/*/manual-assignment/expire' => Http::response(['success' => true], 200),
        ]);

        $response = (new BookingController())->expireRideOffer($this->driverOfferRequest($bookingId));
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['success']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), "/bookings/{$bookingId}/manual-assignment/expire")
            && (string) $request['driver_id'] === (string) $driverId);
    }

    public function test_auto_dispatch_expiry_uses_auto_dispatch_reject_socket_endpoint(): void
    {
        $driverId = $this->createAuthenticatedDriver();
        $userId = $this->createUser();
        $bookingId = DB::table('bookings')->insertGetId([
            'user_id' => $userId,
            'driver' => $driverId,
            'pending_driver_id' => null,
            'vehicle' => '4',
            'pickup_time' => 'asap',
            'pickup_time_type' => 'asap',
            'booking_status' => 'pending',
            'booking_date' => now()->toDateString(),
            'dispatcher_action' => 'Nearest driver dispatch active - offered to driver',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'http://socket.test/bookings/*/auto-dispatch/reject' => Http::response(['success' => true], 200),
        ]);

        $response = (new BookingController())->expireRideOffer($this->driverOfferRequest($bookingId));
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['success']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), "/bookings/{$bookingId}/auto-dispatch/reject")
            && (string) $request['driver_id'] === (string) $driverId);
    }

    public function test_expiry_for_unoffered_ride_skips_without_socket_call(): void
    {
        $this->createAuthenticatedDriver();
        $userId = $this->createUser();
        $bookingId = DB::table('bookings')->insertGetId([
            'user_id' => $userId,
            'driver' => null,
            'pending_driver_id' => null,
            'vehicle' => '4',
            'pickup_time' => 'asap',
            'pickup_time_type' => 'asap',
            'booking_status' => 'pending',
            'booking_date' => now()->toDateString(),
            'dispatcher_action' => 'Waiting for dispatch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake();

        $response = (new BookingController())->expireRideOffer($this->driverOfferRequest($bookingId));
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['success']);
        $this->assertTrue($payload['skipped']);
        Http::assertNothingSent();
    }

    private function createAuthenticatedDriver(): int
    {
        $driverId = DB::table('drivers')->insertGetId([
            'name' => 'Driver One',
            'email' => 'driver' . uniqid() . '@example.test',
            'assigned_vehicle' => '4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Auth::guard('driver')->setUser(CompanyDriver::findOrFail($driverId));

        return $driverId;
    }

    private function createUser(): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Customer One',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function driverOfferRequest(int $bookingId): Request
    {
        return Request::create('/api/driver/expire-ride-offer', 'POST', ['ride_id' => $bookingId], [], [], [
            'HTTP_database' => 'tenant_test',
        ]);
    }

    private function createTables(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('assigned_vehicle')->nullable();
            $table->string('password')->nullable();
            $table->string('otp')->nullable();
            $table->string('profile_image')->nullable();
            $table->unsignedInteger('auth_version')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->decimal('rating', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('token')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('driver')->nullable();
            $table->unsignedBigInteger('pending_driver_id')->nullable();
            $table->string('vehicle')->nullable();
            $table->string('pickup_time')->nullable();
            $table->string('pickup_time_type')->nullable();
            $table->boolean('is_scheduled')->default(false);
            $table->boolean('dispatch_released')->default(false);
            $table->date('booking_date')->nullable();
            $table->string('booking_status')->default('pending');
            $table->decimal('distance', 10, 2)->nullable();
            $table->text('dispatcher_action')->nullable();
            $table->timestamps();
        });

        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->timestamps();
        });

        Schema::create('send_new_rides', function (Blueprint $table) {
            $table->id();
            $table->string('booking_id')->nullable();
            $table->string('driver_id')->nullable();
            $table->timestamps();
        });
    }
}
