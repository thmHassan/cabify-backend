<?php

namespace Tests\Unit;

use App\Models\CompanyBooking;
use Tests\TestCase;

class CompanyBookingPickupTimezoneTest extends TestCase
{
    public function test_stored_utc_pickup_is_exposed_in_booking_timezone(): void
    {
        $booking = new CompanyBooking();
        $booking->setRawAttributes([
            'pickup_at' => '2026-07-25 09:30:00',
            'pickup_timezone' => 'Asia/Karachi',
        ]);

        $this->assertSame('2026-07-25T09:30:00+00:00', $booking->pickup_at->toIso8601String());
        $this->assertSame('2026-07-25T14:30:00+05:00', $booking->pickup_at_local);
    }

    public function test_legacy_booking_date_and_time_are_exposed_as_utc_iso_datetime(): void
    {
        $booking = new CompanyBooking();
        $booking->setRawAttributes([
            'booking_date' => '2026-07-25',
            'pickup_time' => '14:30:00',
            'pickup_timezone' => 'Asia/Karachi',
        ]);

        $this->assertSame('2026-07-25T09:30:00+00:00', $booking->pickup_at->toIso8601String());
        $this->assertSame('2026-07-25T14:30:00+05:00', $booking->pickup_at_local);
    }

    public function test_asap_booking_has_no_scheduled_pickup_datetime(): void
    {
        $booking = new CompanyBooking();
        $booking->setRawAttributes([
            'booking_date' => '2026-07-25',
            'pickup_time' => 'asap',
            'pickup_timezone' => 'Asia/Karachi',
        ]);

        $this->assertNull($booking->pickup_at);
        $this->assertNull($booking->pickup_at_local);
    }

    public function test_asap_booking_with_stored_current_instant_exposes_time_and_timezone(): void
    {
        $booking = new CompanyBooking();
        $booking->setRawAttributes([
            'pickup_time_type' => 'asap',
            'is_scheduled' => false,
            'pickup_at' => '2026-07-22 10:16:35',
            'pickup_timezone' => 'Asia/Karachi',
        ]);

        $this->assertSame('2026-07-22T10:16:35+00:00', $booking->pickup_at->toIso8601String());
        $this->assertSame('Asia/Karachi', $booking->pickup_timezone);
        $this->assertSame('2026-07-22T15:16:35+05:00', $booking->pickup_at_local);
    }
}
