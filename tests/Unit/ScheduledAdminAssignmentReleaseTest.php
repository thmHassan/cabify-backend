<?php

namespace Tests\Unit;

use App\Http\Controllers\Driver\BookingController;
use App\Models\CompanyBooking;
use App\Services\BookingDispatchService;
use App\Services\DuePreBookingReleaseService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class ScheduledAdminAssignmentReleaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-08 10:00:00'));
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::connection('sqlite')->getPdo();

        Schema::dropIfExists('settings');
        Schema::dropIfExists('bookings');

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('auto_release_enabled')->nullable();
            $table->integer('default_release_lead_minutes')->nullable();
            $table->string('default_release_mode')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_id')->nullable();
            $table->string('booking_status')->default('pending');
            $table->date('booking_date')->nullable();
            $table->string('pickup_time')->nullable();
            $table->string('pickup_time_type')->nullable();
            $table->boolean('is_scheduled')->default(false);
            $table->boolean('dispatch_released')->default(false);
            $table->dateTime('dispatch_release_at')->nullable();
            $table->string('dispatch_release_mode')->nullable();
            $table->unsignedBigInteger('driver')->nullable();
            $table->unsignedBigInteger('pending_driver_id')->nullable();
            $table->string('dispatcher_action')->nullable();
            $table->timestamps();
        });

        DB::table('settings')->insert([
            'auto_release_enabled' => true,
            'default_release_lead_minutes' => 60,
            'default_release_mode' => 'auto_then_bidding',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('settings');
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_due_release_skips_scheduled_bookings_already_tied_to_driver_assignment(): void
    {
        $unassignedId = $this->insertScheduledBooking();
        $this->insertScheduledBooking([
            'driver' => 15,
            'dispatcher_action' => 'Manual assignment accepted by driver #15',
        ]);
        $this->insertScheduledBooking([
            'pending_driver_id' => 16,
            'dispatcher_action' => 'Driver selected for pre-job dispatching now',
        ]);

        $dispatchService = new class extends BookingDispatchService {
            public array $releasedBookingIds = [];

            public function releaseForDispatch(CompanyBooking $booking, string $tenantDatabase, ?string $socketApiBaseUrl = null): void
            {
                $this->releasedBookingIds[] = $booking->id;
            }
        };

        $released = (new DuePreBookingReleaseService($dispatchService))
            ->releaseDueForCurrentTenant('1');

        $this->assertSame(1, $released);
        $this->assertSame([$unassignedId], $dispatchService->releasedBookingIds);
    }

    public function test_direct_release_marks_accepted_scheduled_assignment_resolved_without_dispatching(): void
    {
        $bookingId = $this->insertScheduledBooking([
            'driver' => 15,
            'pending_driver_id' => 15,
            'dispatcher_action' => 'Manual assignment accepted by driver #15',
        ]);

        (new BookingDispatchService())->releaseForDispatch(CompanyBooking::findOrFail($bookingId), '1');

        $booking = CompanyBooking::findOrFail($bookingId);
        $this->assertTrue((bool) $booking->dispatch_released);
        $this->assertNull($booking->pending_driver_id);
        $this->assertSame('pending', $booking->booking_status);
        $this->assertSame(15, (int) $booking->driver);
    }

    public function test_assigned_offer_filter_excludes_accepted_scheduled_upcoming_jobs(): void
    {
        $acceptedScheduledId = $this->insertScheduledBooking([
            'driver' => 15,
            'pending_driver_id' => null,
            'dispatch_released' => true,
            'dispatcher_action' => 'Manual assignment accepted by driver #15',
        ]);
        $pendingScheduledOfferId = $this->insertScheduledBooking([
            'pending_driver_id' => 15,
            'dispatcher_action' => 'Created by Dispatcher. Driver selected - scheduled for release at 08 Jul 11:30.',
        ]);
        $legacyImmediateOfferId = $this->insertScheduledBooking([
            'driver' => 15,
            'pickup_time_type' => 'asap',
            'is_scheduled' => false,
            'dispatch_released' => false,
            'dispatcher_action' => 'Created by Dispatcher. Driver selected - dispatching now.',
        ]);

        $assignedOfferQuery = CompanyBooking::where('booking_status', 'pending');
        $this->applyUpcomingRideDriverFilter($assignedOfferQuery, 15, true);
        $assignedOfferIds = $assignedOfferQuery->pluck('id')->all();

        $normalUpcomingQuery = CompanyBooking::where('booking_status', 'pending');
        $this->applyUpcomingRideDriverFilter($normalUpcomingQuery, 15, false);
        $normalUpcomingIds = $normalUpcomingQuery->pluck('id')->all();

        $this->assertNotContains($acceptedScheduledId, $assignedOfferIds);
        $this->assertContains($pendingScheduledOfferId, $assignedOfferIds);
        $this->assertContains($legacyImmediateOfferId, $assignedOfferIds);
        $this->assertContains($acceptedScheduledId, $normalUpcomingIds);
    }

    private function applyUpcomingRideDriverFilter($query, int $driverId, bool $includeAssignedOffers): void
    {
        $controller = new BookingController();
        $method = (new ReflectionClass($controller))->getMethod('applyUpcomingRideDriverFilter');
        $method->setAccessible(true);

        $method->invoke($controller, $query, $driverId, $includeAssignedOffers);
    }

    private function insertScheduledBooking(array $overrides = []): int
    {
        return DB::table('bookings')->insertGetId(array_merge([
            'booking_id' => 'RD' . uniqid(),
            'booking_status' => 'pending',
            'booking_date' => '2026-07-08',
            'pickup_time' => '12:30:00',
            'pickup_time_type' => 'time',
            'is_scheduled' => true,
            'dispatch_released' => false,
            'dispatch_release_at' => '2026-07-08 09:00:00',
            'dispatch_release_mode' => 'auto_then_bidding',
            'driver' => null,
            'pending_driver_id' => null,
            'dispatcher_action' => 'Created by customer app. No driver selected - scheduled for auto release',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
