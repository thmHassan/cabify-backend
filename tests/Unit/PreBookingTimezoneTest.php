<?php

namespace Tests\Unit;

use App\Services\PreBookingService;
use Carbon\Carbon;
use Tests\TestCase;

class PreBookingTimezoneTest extends TestCase
{
    public function testCompanyReleaseTimeIsStoredAsUtcAndDisplayedBackInCompanyTime(): void
    {
        $service = app(PreBookingService::class);

        $storedReleaseAt = $service->parseCompanyDateTimeToUtc('2026-07-05 21:05:00', 'Asia/Dubai');

        $this->assertSame('2026-07-05 17:05:00', $storedReleaseAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $storedReleaseAt->timezoneName);
        $this->assertSame(
            '2026-07-05 21:05:00',
            $service->formatStoredDateTimeForCompany($storedReleaseAt, 'Y-m-d H:i:s', 'Asia/Dubai')
        );
    }

    public function testStoredUtcReleaseTimeComparesAgainstUtcNow(): void
    {
        $service = app(PreBookingService::class);
        Carbon::setTestNow(Carbon::parse('2026-07-05 17:05:00', 'UTC'));

        try {
            $storedReleaseAt = $service->parseCompanyDateTimeToUtc('2026-07-05 21:05:00', 'Asia/Dubai');

            $this->assertTrue($storedReleaseAt->lessThanOrEqualTo(Carbon::now('UTC')));
        } finally {
            Carbon::setTestNow();
        }
    }
}
