<?php

namespace Tests\Unit;

use App\Http\Controllers\Rider\BookingController;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

class RiderBookingCurrencyTest extends TestCase
{
    public function test_currency_is_normalized_to_uppercase(): void
    {
        $this->assertSame('PKR', $this->validateCurrency(' pkr ', 'PKR'));
    }

    public function test_currency_must_match_company_currency(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateCurrency('USD', 'PKR');
    }

    public function test_currency_is_accepted_when_company_currency_is_not_configured(): void
    {
        $this->assertSame('EUR', $this->validateCurrency('eur', null));
    }

    private function validateCurrency(string $currency, ?string $companyCurrency): string
    {
        $method = (new ReflectionClass(BookingController::class))
            ->getMethod('validateRiderBookingCurrency');
        $method->setAccessible(true);

        return $method->invoke(new BookingController(), $currency, $companyCurrency);
    }
}
