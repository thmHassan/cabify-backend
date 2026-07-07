<?php

namespace Tests\Unit;

use App\Http\Controllers\Driver\BookingController;
use App\Models\CompanyDriver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
            $table->string('vehicle')->nullable();
            $table->string('pickup_time')->nullable();
            $table->string('pickup_time_type')->nullable();
            $table->boolean('is_scheduled')->default(false);
            $table->boolean('dispatch_released')->default(false);
            $table->date('booking_date')->nullable();
            $table->string('booking_status')->default('pending');
            $table->decimal('distance', 10, 2)->nullable();
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
