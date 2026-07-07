<?php

namespace Tests\Unit;

use App\Http\Controllers\Driver\BookingController;
use App\Models\CompanyBooking;
use Carbon\Carbon;
use ReflectionClass;
use Tests\TestCase;

class DriverAcceptStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-08 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function resolveStatus(CompanyBooking $booking, bool $isNearestDispatchOffer = false): string
    {
        $controller = new BookingController();
        $method = (new ReflectionClass($controller))->getMethod('resolveStatusAfterDriverAccept');
        $method->setAccessible(true);

        return $method->invoke($controller, $booking, $isNearestDispatchOffer);
    }

    private function shouldResolveAcceptedScheduledRelease(CompanyBooking $booking, string $newStatus): bool
    {
        $controller = new BookingController();
        $method = (new ReflectionClass($controller))->getMethod('shouldResolveAcceptedScheduledRelease');
        $method->setAccessible(true);

        return $method->invoke($controller, $booking, $newStatus);
    }

    public function test_asap_accept_goes_to_current_ride(): void
    {
        $booking = (new CompanyBooking())->forceFill([
            'pickup_time_type' => 'asap',
            'pickup_time' => 'asap',
            'booking_date' => '2026-07-08',
            'is_scheduled' => false,
        ]);

        $this->assertSame('ongoing', $this->resolveStatus($booking));
    }

    public function test_future_scheduled_accept_stays_pending_for_upcoming(): void
    {
        $booking = (new CompanyBooking())->forceFill([
            'pickup_time_type' => 'time',
            'pickup_time' => '12:30:00',
            'booking_date' => '2026-07-08',
            'is_scheduled' => true,
        ]);

        $this->assertSame('pending', $this->resolveStatus($booking));
        $this->assertTrue($this->shouldResolveAcceptedScheduledRelease($booking, 'pending'));
    }

    public function test_near_scheduled_accept_goes_to_current_ride(): void
    {
        $booking = (new CompanyBooking())->forceFill([
            'pickup_time_type' => 'time',
            'pickup_time' => '10:20:00',
            'booking_date' => '2026-07-08',
            'is_scheduled' => true,
        ]);

        $this->assertSame('ongoing', $this->resolveStatus($booking));
        $this->assertFalse($this->shouldResolveAcceptedScheduledRelease($booking, 'ongoing'));
    }
}
