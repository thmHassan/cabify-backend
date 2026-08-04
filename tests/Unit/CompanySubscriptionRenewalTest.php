<?php

namespace Tests\Unit;

use App\Http\Controllers\SuperAdmin\CompanyController;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class CompanySubscriptionRenewalTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_early_renewal_extends_from_the_current_expiry_date(): void
    {
        Carbon::setTestNow('2026-08-04 10:00:00');

        $expiry = $this->invokePrivate('subscriptionExpiryDate', ['2026-08-20', 'monthly']);

        $this->assertSame('2026-09-20', $expiry);
    }

    public function test_expired_plan_renews_from_today(): void
    {
        Carbon::setTestNow('2026-08-04 10:00:00');

        $expiry = $this->invokePrivate('subscriptionExpiryDate', ['2026-07-20', 'monthly']);

        $this->assertSame('2026-09-04', $expiry);
    }

    public function test_stripe_invoice_is_the_idempotency_reference_for_checkout(): void
    {
        $stripeObject = (object) [
            'id' => 'cs_test_123',
            'invoice' => 'in_test_456',
            'payment_intent' => 'pi_test_789',
        ];

        $reference = $this->invokePrivate('stripeRevenueExternalReference', [$stripeObject]);

        $this->assertSame('stripe_invoice:in_test_456', $reference);
    }

    private function invokePrivate(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(CompanyController::class, $method);

        return $reflection->invokeArgs(new CompanyController, $arguments);
    }
}
