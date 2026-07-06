<?php

namespace Tests\Unit;

use App\Models\AccountStatement;
use App\Models\AccountStatementItem;
use App\Models\CompanyAccount;
use App\Models\CompanyBooking;
use App\Models\CompanyDriver;
use App\Models\DriverPackage;
use App\Models\DriverSettlement;
use App\Models\DriverSettlementItem;
use App\Models\FinancePayment;
use App\Models\FinanceSetting;
use App\Models\WalletTransaction;
use App\Http\Controllers\Company\FinanceController;
use App\Services\FinanceEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::connection('sqlite')->getPdo();

        $this->createSchema();
    }

    public function test_old_account_payment_yes_is_treated_as_historically_collected(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $booking = $this->booking([
            'account' => (string) $account->id,
            'account_payment' => 'yes',
            'booking_amount' => '100',
        ]);

        $finance = app(FinanceEngine::class)->rideFinance($booking->fresh(['accountDetail', 'driverDetail']));

        $this->assertSame(100.0, $finance['paid_amount']);
        $this->assertSame(0.0, $finance['account_receivable']);
        $this->assertSame('unbilled', $finance['statement_status']);
    }

    public function test_old_account_payment_no_stays_receivable_until_finance_records_exist(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $booking = $this->booking([
            'account' => (string) $account->id,
            'account_payment' => 'no',
            'booking_amount' => '125',
        ]);

        $finance = app(FinanceEngine::class)->rideFinance($booking->fresh(['accountDetail', 'driverDetail']));

        $this->assertSame(0.0, $finance['paid_amount']);
        $this->assertSame(125.0, $finance['account_receivable']);
    }

    public function test_finance_statement_overrides_legacy_account_payment_flag(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $booking = $this->booking([
            'account' => (string) $account->id,
            'account_payment' => 'yes',
            'booking_amount' => '200',
        ]);

        $statement = AccountStatement::create([
            'statement_number' => 'STMT-1',
            'account_id' => $account->id,
            'subtotal' => 200,
            'total_amount' => 200,
            'paid_amount' => 0,
            'balance_amount' => 200,
            'status' => 'sent',
        ]);
        $statement->items()->create([
            'booking_id' => $booking->id,
            'booking_date' => $booking->booking_date,
            'fare_amount' => 200,
            'total_amount' => 200,
            'payment_channel' => 'account',
            'booking_status' => 'completed',
        ]);

        $finance = app(FinanceEngine::class)->rideFinance($booking->fresh(['accountDetail', 'driverDetail']));

        $this->assertSame(0.0, $finance['paid_amount']);
        $this->assertSame(200.0, $finance['account_receivable']);
        $this->assertSame('sent', $finance['statement_status']);
    }

    public function test_cash_and_online_rides_calculate_driver_liability_from_commission(): void
    {
        FinanceSetting::query()->update([
            'default_driver_commission_percent' => 10,
        ]);

        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $cashBooking = $this->booking([
            'driver' => (string) $driver->id,
            'payment_method' => 'cash',
            'booking_amount' => '100',
        ]);
        $onlineBooking = $this->booking([
            'driver' => (string) $driver->id,
            'payment_method' => 'online',
            'payment_status' => 'completed',
            'booking_amount' => '100',
        ]);

        $engine = app(FinanceEngine::class);
        $cash = $engine->rideFinance($cashBooking->fresh(['accountDetail', 'driverDetail']));
        $online = $engine->rideFinance($onlineBooking->fresh(['accountDetail', 'driverDetail']));

        $this->assertSame(100.0, $cash['driver_cash_collected']);
        $this->assertSame(10.0, $cash['driver_owes_company']);
        $this->assertSame(0.0, $cash['company_owes_driver']);

        $this->assertSame(100.0, $online['company_collected']);
        $this->assertSame(90.0, $online['company_owes_driver']);
        $this->assertSame(0.0, $online['driver_owes_company']);
    }

    public function test_old_ride_amounts_use_fare_fallback_order(): void
    {
        $offeredFallback = $this->booking([
            'booking_amount' => null,
            'offered_amount' => '$88.50',
            'recommended_amount' => '120',
        ]);
        $recommendedFallback = $this->booking([
            'booking_amount' => null,
            'offered_amount' => null,
            'recommended_amount' => '72.25',
        ]);

        $engine = app(FinanceEngine::class);

        $this->assertSame(88.5, $engine->rideFinance($offeredFallback->fresh(['accountDetail', 'driverDetail']))['fare_amount']);
        $this->assertSame(72.25, $engine->rideFinance($recommendedFallback->fresh(['accountDetail', 'driverDetail']))['fare_amount']);
    }

    public function test_summary_warns_about_missing_old_fares_and_missing_completed_drivers(): void
    {
        $this->booking([
            'booking_amount' => null,
            'offered_amount' => null,
            'recommended_amount' => null,
            'driver' => null,
        ]);

        $warnings = app(FinanceEngine::class)->summary()['warnings'];

        $this->assertContains('Some old rides have no fare amount. They are shown with zero until corrected.', $warnings);
        $this->assertContains('Some completed rides do not have a driver assigned, so they cannot be driver-settled.', $warnings);
    }

    public function test_old_package_types_are_normalized_in_finance_center(): void
    {
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        DriverPackage::create([
            'driver_id' => (string) $driver->id,
            'package_type' => 'Packages Postpaid',
            'package_top_up_name' => 'Legacy Postpaid',
            'package_top_up_amount' => '0',
            'post_paid_amount' => '25.50',
            'pending_rides' => '3',
            'commission_per' => '12.5',
        ]);
        DriverPackage::create([
            'driver_id' => (string) $driver->id,
            'package_type' => 'Per Ride Commission Topup',
            'package_top_up_name' => 'Legacy Topup',
            'package_top_up_amount' => '40',
            'commission_per' => '10',
        ]);

        $rows = app(FinanceEngine::class)->packages([], 20)->getCollection();

        $this->assertSame(['top_up', 'post_paid'], $rows->pluck('package_type')->all());
        $this->assertSame([40.0, 25.5], $rows->pluck('amount')->all());
    }

    public function test_driver_detail_includes_package_and_wallet_history_totals(): void
    {
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One', 'wallet_balance' => '15.25'])->save();
        DriverPackage::create([
            'driver_id' => (string) $driver->id,
            'package_type' => 'Packages Postpaid',
            'package_top_up_name' => 'Postpaid Legacy',
            'post_paid_amount' => '25.50',
        ]);
        $walletAdd = new WalletTransaction();
        $walletAdd->forceFill([
            'user_type' => 'driver',
            'user_id' => $driver->id,
            'type' => 'add',
            'amount' => '30',
            'comment' => 'Manual top up',
        ])->save();
        $walletDeduct = new WalletTransaction();
        $walletDeduct->forceFill([
            'user_type' => 'driver',
            'user_id' => $driver->id,
            'type' => 'deduct',
            'amount' => '$12.75',
            'comment' => 'Package purchase',
        ])->save();

        $detail = app(FinanceEngine::class)->driverDetail((int) $driver->id);

        $this->assertSame(25.5, $detail['totals']['package_spend']);
        $this->assertSame(30.0, $detail['totals']['wallet_additions']);
        $this->assertSame(12.75, $detail['totals']['wallet_deductions']);
        $this->assertSame(['deduct', 'add'], $detail['wallet']->pluck('type')->all());
        $this->assertSame([12.75, 30.0], $detail['wallet']->pluck('amount')->all());
    }

    public function test_driver_detail_date_filters_apply_to_package_and_wallet_history(): void
    {
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();

        $oldPackage = DriverPackage::create([
            'driver_id' => (string) $driver->id,
            'package_type' => 'Packages Postpaid',
            'package_top_up_name' => 'Old Package',
            'post_paid_amount' => '50',
        ]);
        $oldPackage->forceFill([
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-01 10:00:00',
        ])->save();

        $currentPackage = DriverPackage::create([
            'driver_id' => (string) $driver->id,
            'package_type' => 'Per Ride Commission Topup',
            'package_top_up_name' => 'Current Package',
            'package_top_up_amount' => '25',
        ]);
        $currentPackage->forceFill([
            'created_at' => '2026-07-07 10:00:00',
            'updated_at' => '2026-07-07 10:00:00',
        ])->save();

        $oldWallet = new WalletTransaction();
        $oldWallet->forceFill([
            'user_type' => 'driver',
            'user_id' => $driver->id,
            'type' => 'deduct',
            'amount' => '15',
            'comment' => 'Old deduction',
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-01 10:00:00',
        ])->save();

        $currentWallet = new WalletTransaction();
        $currentWallet->forceFill([
            'user_type' => 'driver',
            'user_id' => $driver->id,
            'type' => 'add',
            'amount' => '40',
            'comment' => 'Current top up',
            'created_at' => '2026-07-07 10:00:00',
            'updated_at' => '2026-07-07 10:00:00',
        ])->save();

        $detail = app(FinanceEngine::class)->driverDetail((int) $driver->id, [
            'start_date' => '2026-07-07',
            'end_date' => '2026-07-07',
        ]);

        $this->assertSame(25.0, $detail['totals']['package_spend']);
        $this->assertSame(40.0, $detail['totals']['wallet_additions']);
        $this->assertSame(0.0, $detail['totals']['wallet_deductions']);
        $this->assertSame(['Current Package'], $detail['packages']->pluck('package_name')->all());
        $this->assertSame(['Current top up'], $detail['wallet']->pluck('comment')->all());
    }

    public function test_new_company_with_no_finance_data_returns_clean_zero_summary(): void
    {
        $summary = app(FinanceEngine::class)->summary();

        $this->assertSame(0, $summary['totals']['completed_rides']);
        $this->assertSame(0.0, $summary['totals']['gross_revenue']);
        $this->assertSame(0.0, $summary['totals']['account_receivable']);
        $this->assertSame(0, $summary['totals']['statements_open']);
        $this->assertSame(0, $summary['totals']['settlements_open']);
        $this->assertSame([], $summary['warnings']);
    }

    public function test_summary_totals_respect_payment_channel_filter(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $this->booking([
            'payment_method' => 'cash',
            'booking_amount' => '100',
        ]);
        $this->booking([
            'payment_method' => 'online',
            'payment_status' => 'completed',
            'booking_amount' => '200',
        ]);
        $this->booking([
            'account' => (string) $account->id,
            'booking_amount' => '300',
        ]);

        $engine = app(FinanceEngine::class);
        $cash = $engine->summary(['payment_channel' => 'cash']);
        $online = $engine->summary(['payment_channel' => 'online']);
        $accountSummary = $engine->summary(['payment_channel' => 'account']);

        $this->assertSame(1, $cash['totals']['completed_rides']);
        $this->assertSame(100.0, $cash['totals']['gross_revenue']);
        $this->assertSame(100.0, $cash['breakdown']['cash']['gross']);

        $this->assertSame(1, $online['totals']['completed_rides']);
        $this->assertSame(200.0, $online['totals']['gross_revenue']);
        $this->assertSame(200.0, $online['breakdown']['online']['gross']);

        $this->assertSame(1, $accountSummary['totals']['completed_rides']);
        $this->assertSame(300.0, $accountSummary['totals']['gross_revenue']);
        $this->assertSame(300.0, $accountSummary['totals']['account_receivable']);
    }

    public function test_online_payment_channel_filter_includes_old_stripe_and_card_methods(): void
    {
        $this->booking([
            'payment_method' => 'stripe',
            'payment_status' => 'completed',
            'booking_amount' => '120',
        ]);
        $this->booking([
            'payment_method' => 'Card',
            'payment_status' => 'completed',
            'booking_amount' => '80',
        ]);
        $this->booking([
            'payment_method' => 'cash',
            'booking_amount' => '50',
        ]);

        $summary = app(FinanceEngine::class)->summary(['payment_channel' => 'online']);
        $rides = app(FinanceEngine::class)->rides(['payment_channel' => 'online'], 20)->getCollection();

        $this->assertSame(2, $summary['totals']['completed_rides']);
        $this->assertSame(200.0, $summary['totals']['gross_revenue']);
        $this->assertSame([80.0, 120.0], $rides->pluck('fare_amount')->all());
    }

    public function test_summary_totals_respect_booking_date_filter(): void
    {
        $this->booking([
            'booking_date' => '2026-07-01',
            'booking_amount' => '100',
        ]);
        $this->booking([
            'booking_date' => '2026-07-07',
            'booking_amount' => '200',
        ]);

        $summary = app(FinanceEngine::class)->summary([
            'start_date' => '2026-07-07',
            'end_date' => '2026-07-07',
        ]);

        $this->assertSame(1, $summary['totals']['completed_rides']);
        $this->assertSame(200.0, $summary['totals']['gross_revenue']);
    }

    public function test_summary_payment_total_matches_filtered_payment_rows(): void
    {
        FinancePayment::create([
            'payment_number' => 'PAY-MATCH-1',
            'payer_type' => 'account',
            'receiver_type' => 'company',
            'channel' => 'manual_collection',
            'amount' => 25,
            'status' => 'posted',
            'payment_date' => '2026-07-07',
            'reference' => 'MATCH-REF',
        ]);
        FinancePayment::create([
            'payment_number' => 'PAY-NO-CHANNEL',
            'payer_type' => 'account',
            'receiver_type' => 'company',
            'channel' => 'manual_settlement',
            'amount' => 40,
            'status' => 'posted',
            'payment_date' => '2026-07-07',
            'reference' => 'MATCH-REF',
        ]);
        FinancePayment::create([
            'payment_number' => 'PAY-NO-DATE',
            'payer_type' => 'account',
            'receiver_type' => 'company',
            'channel' => 'manual_collection',
            'amount' => 50,
            'status' => 'posted',
            'payment_date' => '2026-07-01',
            'reference' => 'MATCH-REF',
        ]);
        FinancePayment::create([
            'payment_number' => 'PAY-NO-SEARCH',
            'payer_type' => 'account',
            'receiver_type' => 'company',
            'channel' => 'manual_collection',
            'amount' => 60,
            'status' => 'posted',
            'payment_date' => '2026-07-07',
            'reference' => 'OTHER-REF',
        ]);

        $filters = [
            'start_date' => '2026-07-07',
            'end_date' => '2026-07-07',
            'payment_channel' => 'manual_collection',
            'search' => 'MATCH',
        ];
        $engine = app(FinanceEngine::class);
        $summary = $engine->summary($filters);
        $payments = $engine->payments($filters, 20)->getCollection();

        $this->assertSame(25.0, $summary['totals']['payments_posted']);
        $this->assertSame(25.0, (float) $payments->sum('amount'));
        $this->assertSame(['PAY-MATCH-1'], $payments->pluck('payment_number')->all());
    }

    public function test_pending_account_rides_are_visible_but_not_receivable_or_statement_eligible(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $booking = $this->booking([
            'account' => (string) $account->id,
            'booking_status' => 'pending',
            'booking_amount' => '150',
        ]);

        $engine = app(FinanceEngine::class);
        $finance = $engine->rideFinance($booking->fresh(['accountDetail', 'driverDetail']));
        $ledger = $engine->accountLedger((int) $account->id);
        $summary = $engine->summary();

        $this->assertSame(150.0, $finance['fare_amount']);
        $this->assertSame(0.0, $finance['account_receivable']);
        $this->assertFalse($finance['is_receivable']);
        $this->assertCount(1, $ledger['rows']);
        $this->assertSame(0.0, $ledger['totals']['balance']);
        $this->assertSame(0, $summary['totals']['completed_rides']);
        $this->assertSame(0.0, $summary['totals']['gross_revenue']);
        $this->assertSame([], $engine->eligibleAccountBookingIds((int) $account->id)->all());
    }

    public function test_charged_cancelled_and_no_show_rides_are_financial_but_zero_charge_ones_are_not(): void
    {
        FinanceSetting::query()->update([
            'default_driver_commission_percent' => 10,
        ]);

        $account = CompanyAccount::create(['name' => 'Acme']);
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $cancelledAccountRide = $this->booking([
            'account' => (string) $account->id,
            'booking_status' => 'Cancelled',
            'booking_amount' => '45',
        ]);
        $chargedNoShowRide = $this->booking([
            'driver' => (string) $driver->id,
            'booking_status' => 'No Show',
            'payment_method' => 'cash',
            'booking_amount' => '30',
        ]);
        $this->booking([
            'account' => (string) $account->id,
            'booking_status' => 'no-show',
            'booking_amount' => null,
            'offered_amount' => null,
            'recommended_amount' => null,
        ]);

        $engine = app(FinanceEngine::class);
        $summary = $engine->summary();

        $this->assertSame(2, $summary['totals']['completed_rides']);
        $this->assertSame(75.0, $summary['totals']['gross_revenue']);
        $this->assertSame([$cancelledAccountRide->id], $engine->eligibleAccountBookingIds((int) $account->id)->all());
        $this->assertSame([$chargedNoShowRide->id], $engine->eligibleDriverBookingIds((int) $driver->id)->all());

        $statementResponse = $this->financeController()->storeStatement(
            Request::create('/company/finance/statements', 'POST', [
                'account_id' => $account->id,
                'booking_ids' => [$cancelledAccountRide->id],
            ])
        );
        $settlementResponse = $this->financeController()->storeSettlement(
            Request::create('/company/finance/settlements', 'POST', [
                'driver_id' => $driver->id,
                'booking_ids' => [$chargedNoShowRide->id],
            ])
        );

        $this->assertSame(200, $statementResponse->getStatusCode());
        $this->assertSame(200, $settlementResponse->getStatusCode());
        $this->assertSame(1, AccountStatement::count());
        $this->assertSame(1, DriverSettlement::count());
    }

    public function test_status_filters_match_old_cancelled_and_no_show_spellings(): void
    {
        $this->booking([
            'booking_status' => 'Cancelled',
            'booking_amount' => '40',
        ]);
        $this->booking([
            'booking_status' => 'canceled',
            'booking_amount' => '35',
        ]);
        $this->booking([
            'booking_status' => 'no-show',
            'booking_amount' => '20',
        ]);
        $this->booking([
            'booking_status' => 'No Show',
            'booking_amount' => '15',
        ]);
        $this->booking([
            'booking_status' => 'completed',
            'booking_amount' => '100',
        ]);

        $engine = app(FinanceEngine::class);
        $cancelled = $engine->rides(['status' => 'cancelled'], 20)->getCollection();
        $noShow = $engine->rides(['status' => 'no_show'], 20)->getCollection();

        $this->assertSame([35.0, 40.0], $cancelled->pluck('fare_amount')->all());
        $this->assertSame([15.0, 20.0], $noShow->pluck('fare_amount')->all());
    }

    public function test_missing_finance_settings_row_is_recreated_with_safe_defaults(): void
    {
        FinanceSetting::query()->delete();

        $settings = app(FinanceEngine::class)->settings();

        $this->assertNotNull($settings->id);
        $this->assertSame('after_account_collection', $settings->account_driver_payout_timing);
        $this->assertSame('driver_owes_commission', $settings->cash_driver_collection_policy);
        $this->assertSame('company_owes_driver_net', $settings->online_driver_payout_policy);
    }

    public function test_account_driver_payout_waits_until_account_collection_by_default(): void
    {
        FinanceSetting::query()->update([
            'default_driver_commission_percent' => 10,
            'account_driver_payout_timing' => 'after_account_collection',
        ]);

        $account = CompanyAccount::create(['name' => 'Acme']);
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $booking = $this->booking([
            'account' => (string) $account->id,
            'driver' => (string) $driver->id,
            'account_payment' => 'no',
            'booking_amount' => '100',
        ]);

        $finance = app(FinanceEngine::class)->rideFinance($booking->fresh(['accountDetail', 'driverDetail']));

        $this->assertSame(100.0, $finance['account_receivable']);
        $this->assertSame(0.0, $finance['company_owes_driver']);

        $statement = AccountStatement::create([
            'statement_number' => 'STMT-COLLECTED',
            'account_id' => $account->id,
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 100,
            'balance_amount' => 0,
            'status' => 'collected',
        ]);
        $statement->items()->create([
            'booking_id' => $booking->id,
            'booking_date' => $booking->booking_date,
            'fare_amount' => 100,
            'total_amount' => 100,
            'payment_channel' => 'account',
            'booking_status' => 'completed',
        ]);

        $collectedFinance = app(FinanceEngine::class)->rideFinance($booking->fresh(['accountDetail', 'driverDetail']));

        $this->assertSame(0.0, $collectedFinance['account_receivable']);
        $this->assertSame(90.0, $collectedFinance['company_owes_driver']);
    }

    public function test_active_statement_blocks_duplicate_account_eligibility_but_void_reopens_it(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $booking = $this->booking([
            'account' => (string) $account->id,
            'booking_amount' => '100',
        ]);

        $statement = AccountStatement::create([
            'statement_number' => 'STMT-ACTIVE',
            'account_id' => $account->id,
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'status' => 'sent',
        ]);
        $statement->items()->create([
            'booking_id' => $booking->id,
            'booking_date' => $booking->booking_date,
            'fare_amount' => 100,
            'total_amount' => 100,
            'payment_channel' => 'account',
            'booking_status' => 'completed',
        ]);

        $engine = app(FinanceEngine::class);

        $this->assertSame([], $engine->eligibleAccountBookingIds((int) $account->id)->all());

        $statement->update(['status' => 'void']);

        $this->assertSame([$booking->id], $engine->eligibleAccountBookingIds((int) $account->id)->all());
    }

    public function test_driver_settlement_eligibility_waits_for_account_collection(): void
    {
        FinanceSetting::query()->update([
            'default_driver_commission_percent' => 10,
            'account_driver_payout_timing' => 'after_account_collection',
        ]);

        $account = CompanyAccount::create(['name' => 'Acme']);
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $booking = $this->booking([
            'account' => (string) $account->id,
            'driver' => (string) $driver->id,
            'booking_amount' => '100',
        ]);

        $engine = app(FinanceEngine::class);

        $this->assertSame([], $engine->eligibleDriverBookingIds((int) $driver->id)->all());

        $statement = AccountStatement::create([
            'statement_number' => 'STMT-PAID',
            'account_id' => $account->id,
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 100,
            'balance_amount' => 0,
            'status' => 'collected',
        ]);
        $statement->items()->create([
            'booking_id' => $booking->id,
            'booking_date' => $booking->booking_date,
            'fare_amount' => 100,
            'total_amount' => 100,
            'payment_channel' => 'account',
            'booking_status' => 'completed',
        ]);

        $this->assertSame([$booking->id], $engine->eligibleDriverBookingIds((int) $driver->id)->all());
    }

    public function test_active_settlement_blocks_duplicate_driver_eligibility_but_void_reopens_it(): void
    {
        FinanceSetting::query()->update([
            'default_driver_commission_percent' => 10,
        ]);

        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $booking = $this->booking([
            'driver' => (string) $driver->id,
            'payment_method' => 'cash',
            'booking_amount' => '100',
        ]);

        $settlement = DriverSettlement::create([
            'settlement_number' => 'SETTLE-ACTIVE',
            'driver_id' => $driver->id,
            'status' => 'draft',
        ]);
        $settlement->items()->create([
            'booking_id' => $booking->id,
        ]);

        $engine = app(FinanceEngine::class);

        $this->assertSame([], $engine->eligibleDriverBookingIds((int) $driver->id)->all());

        $settlement->update(['status' => 'void']);

        $this->assertSame([$booking->id], $engine->eligibleDriverBookingIds((int) $driver->id)->all());
    }

    public function test_partial_driver_settlement_payment_stays_partial(): void
    {
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $settlement = DriverSettlement::create([
            'settlement_number' => 'SETTLE-PARTIAL',
            'driver_id' => $driver->id,
            'net_amount' => 100,
            'paid_amount' => 0,
            'status' => 'draft',
        ]);

        $response = $this->financeController()->markSettlementSettled(
            Request::create('/company/finance/settlements/' . $settlement->id . '/mark-settled', 'POST', [
                'amount' => 40,
                'channel' => 'manual_settlement',
            ]),
            $settlement->id
        );

        $settlement->refresh();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('partial', $settlement->status);
        $this->assertSame(40.0, (float) $settlement->paid_amount);
        $this->assertNull($settlement->settled_at);
        $this->assertSame(40.0, (float) FinancePayment::where('driver_settlement_id', $settlement->id)->sum('amount'));
    }

    public function test_remaining_driver_settlement_payment_marks_settled_without_overpaying(): void
    {
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $settlement = DriverSettlement::create([
            'settlement_number' => 'SETTLE-FULL',
            'driver_id' => $driver->id,
            'net_amount' => 100,
            'paid_amount' => 0,
            'status' => 'draft',
        ]);
        FinancePayment::create([
            'payment_number' => 'PAY-OLD',
            'payer_type' => 'company',
            'receiver_type' => 'driver',
            'receiver_id' => $driver->id,
            'channel' => 'manual_settlement',
            'amount' => 40,
            'status' => 'posted',
            'driver_settlement_id' => $settlement->id,
        ]);

        $response = $this->financeController()->markSettlementSettled(
            Request::create('/company/finance/settlements/' . $settlement->id . '/mark-settled', 'POST', [
                'amount' => 100,
                'channel' => 'manual_settlement',
            ]),
            $settlement->id
        );

        $settlement->refresh();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('settled', $settlement->status);
        $this->assertSame(100.0, (float) $settlement->paid_amount);
        $this->assertNotNull($settlement->settled_at);
        $this->assertSame(100.0, (float) FinancePayment::where('driver_settlement_id', $settlement->id)->sum('amount'));
    }

    public function test_partial_statement_collection_stays_partial(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $statement = AccountStatement::create([
            'statement_number' => 'STMT-PARTIAL',
            'account_id' => $account->id,
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'status' => 'sent',
        ]);

        $response = $this->financeController()->collectStatement(
            Request::create('/company/finance/statements/' . $statement->id . '/collect', 'POST', [
                'amount' => 35,
                'channel' => 'bank_transfer',
            ]),
            $statement->id
        );

        $statement->refresh();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('partial', $statement->status);
        $this->assertSame(35.0, (float) $statement->paid_amount);
        $this->assertSame(65.0, (float) $statement->balance_amount);
        $this->assertNull($statement->collected_at);
        $this->assertSame(35.0, (float) FinancePayment::where('account_statement_id', $statement->id)->sum('amount'));
    }

    public function test_remaining_statement_collection_marks_collected_without_overpaying(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $statement = AccountStatement::create([
            'statement_number' => 'STMT-CAP',
            'account_id' => $account->id,
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 35,
            'balance_amount' => 65,
            'status' => 'partial',
        ]);
        FinancePayment::create([
            'payment_number' => 'PAY-ACCOUNT-OLD',
            'payer_type' => 'account',
            'payer_id' => $account->id,
            'receiver_type' => 'company',
            'channel' => 'bank_transfer',
            'amount' => 35,
            'status' => 'posted',
            'account_statement_id' => $statement->id,
        ]);

        $response = $this->financeController()->collectStatement(
            Request::create('/company/finance/statements/' . $statement->id . '/collect', 'POST', [
                'amount' => 100,
                'channel' => 'bank_transfer',
            ]),
            $statement->id
        );

        $statement->refresh();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('collected', $statement->status);
        $this->assertSame(100.0, (float) $statement->paid_amount);
        $this->assertSame(0.0, (float) $statement->balance_amount);
        $this->assertNotNull($statement->collected_at);
        $this->assertSame(100.0, (float) FinancePayment::where('account_statement_id', $statement->id)->sum('amount'));
    }

    public function test_statement_with_posted_payment_cannot_be_voided(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $statement = AccountStatement::create([
            'statement_number' => 'STMT-PAID-NO-VOID',
            'account_id' => $account->id,
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 25,
            'balance_amount' => 75,
            'status' => 'partial',
        ]);
        FinancePayment::create([
            'payment_number' => 'PAY-STMT-NO-VOID',
            'payer_type' => 'account',
            'payer_id' => $account->id,
            'receiver_type' => 'company',
            'channel' => 'bank_transfer',
            'amount' => 25,
            'status' => 'posted',
            'account_statement_id' => $statement->id,
        ]);

        $response = $this->financeController()->voidStatement($statement->id);

        $statement->refresh();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('partial', $statement->status);
    }

    public function test_settlement_with_posted_payment_cannot_be_voided(): void
    {
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $settlement = DriverSettlement::create([
            'settlement_number' => 'SETTLE-PAID-NO-VOID',
            'driver_id' => $driver->id,
            'net_amount' => 100,
            'paid_amount' => 25,
            'status' => 'partial',
        ]);
        FinancePayment::create([
            'payment_number' => 'PAY-SETTLE-NO-VOID',
            'payer_type' => 'company',
            'receiver_type' => 'driver',
            'receiver_id' => $driver->id,
            'channel' => 'manual_settlement',
            'amount' => 25,
            'status' => 'posted',
            'driver_settlement_id' => $settlement->id,
        ]);

        $response = $this->financeController()->voidSettlement($settlement->id);

        $settlement->refresh();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('partial', $settlement->status);
    }

    public function test_generic_payment_cannot_overpay_linked_statement(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $statement = AccountStatement::create([
            'statement_number' => 'STMT-GENERIC-CAP',
            'account_id' => $account->id,
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'status' => 'sent',
        ]);

        $response = $this->financeController()->storePayment(
            Request::create('/company/finance/payments', 'POST', [
                'payer_type' => 'account',
                'payer_id' => $account->id,
                'receiver_type' => 'company',
                'channel' => 'bank_transfer',
                'amount' => 125,
                'account_statement_id' => $statement->id,
            ])
        );

        $statement->refresh();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, FinancePayment::where('account_statement_id', $statement->id)->count());
        $this->assertSame('sent', $statement->status);
    }

    public function test_generic_payment_cannot_post_to_void_statement(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $statement = AccountStatement::create([
            'statement_number' => 'STMT-VOID-PAY',
            'account_id' => $account->id,
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'status' => 'void',
        ]);

        $response = $this->financeController()->storePayment(
            Request::create('/company/finance/payments', 'POST', [
                'payer_type' => 'account',
                'payer_id' => $account->id,
                'receiver_type' => 'company',
                'channel' => 'bank_transfer',
                'amount' => 50,
                'account_statement_id' => $statement->id,
            ])
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, FinancePayment::where('account_statement_id', $statement->id)->count());
    }

    public function test_void_statement_cannot_be_collected_through_collection_action(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $statement = AccountStatement::create([
            'statement_number' => 'STMT-VOID-COLLECT',
            'account_id' => $account->id,
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'status' => 'void',
        ]);

        $response = $this->financeController()->collectStatement(
            Request::create('/company/finance/statements/' . $statement->id . '/collect', 'POST', [
                'amount' => 50,
                'channel' => 'bank_transfer',
            ]),
            $statement->id
        );

        $statement->refresh();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('void', $statement->status);
        $this->assertSame(0, FinancePayment::where('account_statement_id', $statement->id)->count());
    }

    public function test_generic_payment_cannot_overpay_linked_settlement(): void
    {
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $settlement = DriverSettlement::create([
            'settlement_number' => 'SETTLE-GENERIC-CAP',
            'driver_id' => $driver->id,
            'net_amount' => 100,
            'paid_amount' => 0,
            'status' => 'draft',
        ]);

        $response = $this->financeController()->storePayment(
            Request::create('/company/finance/payments', 'POST', [
                'payer_type' => 'company',
                'receiver_type' => 'driver',
                'receiver_id' => $driver->id,
                'channel' => 'manual_settlement',
                'amount' => 125,
                'driver_settlement_id' => $settlement->id,
            ])
        );

        $settlement->refresh();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, FinancePayment::where('driver_settlement_id', $settlement->id)->count());
        $this->assertSame('draft', $settlement->status);
    }

    public function test_generic_payment_cannot_post_to_void_settlement(): void
    {
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $settlement = DriverSettlement::create([
            'settlement_number' => 'SETTLE-VOID-PAY',
            'driver_id' => $driver->id,
            'net_amount' => 100,
            'paid_amount' => 0,
            'status' => 'void',
        ]);

        $response = $this->financeController()->storePayment(
            Request::create('/company/finance/payments', 'POST', [
                'payer_type' => 'company',
                'receiver_type' => 'driver',
                'receiver_id' => $driver->id,
                'channel' => 'manual_settlement',
                'amount' => 50,
                'driver_settlement_id' => $settlement->id,
            ])
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, FinancePayment::where('driver_settlement_id', $settlement->id)->count());
    }

    public function test_void_settlement_cannot_be_paid_through_settlement_action(): void
    {
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $settlement = DriverSettlement::create([
            'settlement_number' => 'SETTLE-VOID-MARK',
            'driver_id' => $driver->id,
            'net_amount' => 100,
            'paid_amount' => 0,
            'status' => 'void',
        ]);

        $response = $this->financeController()->markSettlementSettled(
            Request::create('/company/finance/settlements/' . $settlement->id . '/mark-settled', 'POST', [
                'amount' => 50,
                'channel' => 'manual_settlement',
            ]),
            $settlement->id
        );

        $settlement->refresh();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('void', $settlement->status);
        $this->assertSame(0, FinancePayment::where('driver_settlement_id', $settlement->id)->count());
    }

    public function test_statement_creation_freezes_items_and_totals(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $booking = $this->booking([
            'account' => (string) $account->id,
            'driver' => (string) $driver->id,
            'booking_amount' => '125',
        ]);

        $response = $this->financeController()->storeStatement(
            Request::create('/company/finance/statements', 'POST', [
                'account_id' => $account->id,
                'booking_ids' => [$booking->id],
                'adjustment_amount' => 5,
            ])
        );

        $statement = AccountStatement::with('items')->first();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(125.0, (float) $statement->subtotal);
        $this->assertSame(130.0, (float) $statement->total_amount);
        $this->assertSame(130.0, (float) $statement->balance_amount);
        $this->assertCount(1, $statement->items);
        $this->assertSame(125.0, (float) $statement->items->first()->total_amount);

        $booking->forceFill(['booking_amount' => '999'])->save();
        $statement->refresh();

        $this->assertSame(130.0, (float) $statement->total_amount);
        $this->assertSame(125.0, (float) $statement->items()->first()->total_amount);
    }

    public function test_repeated_statement_create_with_same_booking_is_rejected(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $booking = $this->booking([
            'account' => (string) $account->id,
            'booking_amount' => '100',
        ]);

        $firstResponse = $this->financeController()->storeStatement(
            Request::create('/company/finance/statements', 'POST', [
                'account_id' => $account->id,
                'booking_ids' => [$booking->id],
            ])
        );
        $secondResponse = $this->financeController()->storeStatement(
            Request::create('/company/finance/statements', 'POST', [
                'account_id' => $account->id,
                'booking_ids' => [$booking->id],
            ])
        );

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertSame(422, $secondResponse->getStatusCode());
        $this->assertSame(1, AccountStatement::count());
        $this->assertSame(1, AccountStatementItem::where('booking_id', $booking->id)->count());
    }

    public function test_settlement_creation_freezes_items_and_totals(): void
    {
        FinanceSetting::query()->update([
            'default_driver_commission_percent' => 10,
        ]);

        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $booking = $this->booking([
            'driver' => (string) $driver->id,
            'payment_method' => 'online',
            'payment_status' => 'completed',
            'booking_amount' => '200',
        ]);

        $response = $this->financeController()->storeSettlement(
            Request::create('/company/finance/settlements', 'POST', [
                'driver_id' => $driver->id,
                'booking_ids' => [$booking->id],
                'adjustment_amount' => -5,
            ])
        );

        $settlement = DriverSettlement::with('items')->first();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(200.0, (float) $settlement->gross_fares);
        $this->assertSame(180.0, (float) $settlement->company_owes_driver);
        $this->assertSame(0.0, (float) $settlement->driver_owes_company);
        $this->assertSame(175.0, (float) $settlement->net_amount);
        $this->assertCount(1, $settlement->items);
        $this->assertSame(180.0, (float) $settlement->items->first()->net_amount);

        $booking->forceFill(['booking_amount' => '999'])->save();
        $settlement->refresh();

        $this->assertSame(200.0, (float) $settlement->gross_fares);
        $this->assertSame(175.0, (float) $settlement->net_amount);
        $this->assertSame(180.0, (float) $settlement->items()->first()->net_amount);
    }

    public function test_repeated_settlement_create_with_same_booking_is_rejected(): void
    {
        FinanceSetting::query()->update([
            'default_driver_commission_percent' => 10,
        ]);

        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $booking = $this->booking([
            'driver' => (string) $driver->id,
            'payment_method' => 'cash',
            'booking_amount' => '100',
        ]);

        $firstResponse = $this->financeController()->storeSettlement(
            Request::create('/company/finance/settlements', 'POST', [
                'driver_id' => $driver->id,
                'booking_ids' => [$booking->id],
            ])
        );
        $secondResponse = $this->financeController()->storeSettlement(
            Request::create('/company/finance/settlements', 'POST', [
                'driver_id' => $driver->id,
                'booking_ids' => [$booking->id],
            ])
        );

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertSame(422, $secondResponse->getStatusCode());
        $this->assertSame(1, DriverSettlement::count());
        $this->assertSame(1, DriverSettlementItem::where('booking_id', $booking->id)->count());
    }

    public function test_finance_statement_actions_do_not_mutate_operational_booking_fields(): void
    {
        $account = CompanyAccount::create(['name' => 'Acme']);
        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $booking = $this->booking([
            'account' => (string) $account->id,
            'driver' => (string) $driver->id,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'account_payment' => 'no',
            'booking_amount' => '125',
            'pickup_point' => 'Old Pickup',
            'destination_point' => 'Old Dropoff',
        ]);
        $before = $this->bookingOperationalSnapshot($booking);

        $response = $this->financeController()->storeStatement(
            Request::create('/company/finance/statements', 'POST', [
                'account_id' => $account->id,
                'booking_ids' => [$booking->id],
            ])
        );
        $statement = AccountStatement::first();
        $sendResponse = $this->financeController()->sendStatement($statement->id);
        $collectResponse = $this->financeController()->collectStatement(
            Request::create('/company/finance/statements/' . $statement->id . '/collect', 'POST', [
                'amount' => 125,
                'channel' => 'bank_transfer',
            ]),
            $statement->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(200, $sendResponse->getStatusCode());
        $this->assertSame(200, $collectResponse->getStatusCode());
        $this->assertSame($before, $this->bookingOperationalSnapshot($booking->fresh()));
    }

    public function test_finance_settlement_actions_do_not_mutate_operational_booking_fields(): void
    {
        FinanceSetting::query()->update([
            'default_driver_commission_percent' => 10,
        ]);

        $driver = new CompanyDriver();
        $driver->forceFill(['name' => 'Driver One'])->save();
        $booking = $this->booking([
            'driver' => (string) $driver->id,
            'payment_method' => 'online',
            'payment_status' => 'completed',
            'account_payment' => 'no',
            'booking_amount' => '200',
            'pickup_point' => 'Online Pickup',
            'destination_point' => 'Online Dropoff',
        ]);
        $before = $this->bookingOperationalSnapshot($booking);

        $response = $this->financeController()->storeSettlement(
            Request::create('/company/finance/settlements', 'POST', [
                'driver_id' => $driver->id,
                'booking_ids' => [$booking->id],
            ])
        );
        $settlement = DriverSettlement::first();
        $settleResponse = $this->financeController()->markSettlementSettled(
            Request::create('/company/finance/settlements/' . $settlement->id . '/mark-settled', 'POST', [
                'amount' => 180,
                'channel' => 'manual_settlement',
            ]),
            $settlement->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(200, $settleResponse->getStatusCode());
        $this->assertSame($before, $this->bookingOperationalSnapshot($booking->fresh()));
    }

    private function booking(array $overrides = []): CompanyBooking
    {
        $booking = new CompanyBooking();
        $booking->forceFill(array_merge([
            'booking_date' => '2026-07-07',
            'pickup_time' => '10:00',
            'booking_status' => 'completed',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'account_payment' => 'no',
            'booking_amount' => '100',
            'offered_amount' => null,
            'recommended_amount' => null,
            'name' => 'Passenger',
            'pickup_point' => 'Pickup',
            'destination_point' => 'Dropoff',
            'distance' => '1000',
        ], $overrides))->save();

        return $booking;
    }

    private function bookingOperationalSnapshot(CompanyBooking $booking): array
    {
        return $booking->only([
            'booking_date',
            'pickup_time',
            'booking_status',
            'payment_method',
            'payment_status',
            'account_payment',
            'booking_amount',
            'offered_amount',
            'recommended_amount',
            'name',
            'pickup_point',
            'destination_point',
            'account',
            'driver',
            'distance',
        ]);
    }

    private function financeController(): FinanceController
    {
        return new FinanceController(app(FinanceEngine::class));
    }

    private function createSchema(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_currency')->nullable();
            $table->string('package_percentage')->nullable();
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_no')->nullable();
            $table->string('company')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_no')->nullable();
            $table->string('plate_no')->nullable();
            $table->string('status')->nullable();
            $table->string('wallet_balance')->nullable();
            $table->string('vehicle_name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->date('booking_date')->nullable();
            $table->string('pickup_time')->nullable();
            $table->string('booking_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('account_payment')->nullable();
            $table->string('booking_amount')->nullable();
            $table->string('offered_amount')->nullable();
            $table->string('recommended_amount')->nullable();
            $table->string('name')->nullable();
            $table->string('pickup_point')->nullable();
            $table->string('destination_point')->nullable();
            $table->string('account')->nullable();
            $table->string('driver')->nullable();
            $table->string('distance')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('account_driver_payout_timing')->default('after_account_collection');
            $table->string('cash_driver_collection_policy')->default('driver_owes_commission');
            $table->string('online_driver_payout_policy')->default('company_owes_driver_net');
            $table->decimal('default_driver_commission_percent', 10, 2)->default(0);
            $table->decimal('default_driver_commission_fixed', 10, 2)->default(0);
            $table->string('stripe_fee_policy')->default('company_cost');
            $table->string('statement_prefix')->default('STMT');
            $table->string('settlement_prefix')->default('SETTLE');
            $table->timestamps();
        });

        Schema::create('finance_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->nullable();
            $table->string('payer_type')->nullable();
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->string('receiver_type')->nullable();
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->string('channel')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('posted');
            $table->date('payment_date')->nullable();
            $table->unsignedBigInteger('account_statement_id')->nullable();
            $table->unsignedBigInteger('driver_settlement_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('account_statements', function (Blueprint $table) {
            $table->id();
            $table->string('statement_number')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('adjustment_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance_amount', 12, 2)->default(0);
            $table->string('currency')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('account_statement_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_statement_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->date('booking_date')->nullable();
            $table->string('pickup_point')->nullable();
            $table->string('destination_point')->nullable();
            $table->string('driver_name')->nullable();
            $table->decimal('fare_amount', 12, 2)->default(0);
            $table->decimal('extra_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_channel')->nullable();
            $table->string('booking_status')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('driver_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_number')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('gross_fares', 12, 2)->default(0);
            $table->decimal('company_owes_driver', 12, 2)->default(0);
            $table->decimal('driver_owes_company', 12, 2)->default(0);
            $table->decimal('adjustment_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('currency')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('settled_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('driver_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_settlement_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('driver_package_id')->nullable();
            $table->date('item_date')->nullable();
            $table->string('item_type')->default('ride');
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('company_owes_driver', 12, 2)->default(0);
            $table->decimal('driver_owes_company', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('payment_channel')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('driver_packages', function (Blueprint $table) {
            $table->id();
            $table->string('driver_id')->nullable();
            $table->string('package_type')->nullable();
            $table->string('package_top_up_name')->nullable();
            $table->string('package_top_up_amount')->nullable();
            $table->string('post_paid_amount')->nullable();
            $table->string('pending_rides')->nullable();
            $table->string('commission_per')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('amount')->nullable();
            $table->string('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->decimal('rating', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('finance_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        FinanceSetting::create([]);
    }
}
