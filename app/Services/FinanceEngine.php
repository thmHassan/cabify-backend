<?php

namespace App\Services;

use App\Models\AccountStatement;
use App\Models\AccountStatementItem;
use App\Models\CompanyAccount;
use App\Models\CompanyBooking;
use App\Models\CompanyDriver;
use App\Models\CompanySetting;
use App\Models\DriverPackage;
use App\Models\DriverSettlement;
use App\Models\DriverSettlementItem;
use App\Models\FinancePayment;
use App\Models\FinanceSetting;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinanceEngine
{
    public function settings(): FinanceSetting
    {
        return FinanceSetting::firstOrCreate([], [
            'account_driver_payout_timing' => 'after_account_collection',
            'cash_driver_collection_policy' => 'driver_owes_commission',
            'online_driver_payout_policy' => 'company_owes_driver_net',
            'default_driver_commission_percent' => 0,
            'default_driver_commission_fixed' => 0,
            'stripe_fee_policy' => 'company_cost',
            'statement_prefix' => 'STMT',
            'settlement_prefix' => 'SETTLE',
        ]);
    }

    public function companyCurrency(): ?string
    {
        $setting = CompanySetting::orderBy('id', 'DESC')->first();

        return $setting?->company_currency;
    }

    public function summary(array $filters = []): array
    {
        $bookings = $this->bookingQuery($filters)->with(['accountDetail', 'driverDetail'])->get();
        $completed = $bookings->filter(fn ($booking) => $this->isFinancialBooking($booking));
        $rideRows = $completed->map(fn ($booking) => $this->rideFinance($booking));

        $paymentsQuery = FinancePayment::query();
        $this->applyPaymentFilters($paymentsQuery, $filters);

        $statements = AccountStatement::query();
        $this->applyDateFilters($statements, $filters, 'period_end');

        $settlements = DriverSettlement::query();
        $this->applyDateFilters($settlements, $filters, 'period_end');

        return [
            'currency' => $this->companyCurrency(),
            'filters' => $filters,
            'totals' => [
                'completed_rides' => $completed->count(),
                'gross_revenue' => round($rideRows->sum('fare_amount'), 2),
                'account_receivable' => round($rideRows->sum('account_receivable'), 2),
                'company_collected' => round($rideRows->sum('company_collected'), 2),
                'driver_cash_collected' => round($rideRows->sum('driver_cash_collected'), 2),
                'company_owes_drivers' => round($rideRows->sum('company_owes_driver'), 2),
                'drivers_owe_company' => round($rideRows->sum('driver_owes_company'), 2),
                'payments_posted' => round((float) $paymentsQuery->sum('amount'), 2),
                'statements_open' => (clone $statements)->whereIn('status', ['draft', 'sent', 'partial'])->count(),
                'settlements_open' => (clone $settlements)->whereIn('status', ['draft', 'pending', 'partial'])->count(),
            ],
            'breakdown' => [
                'cash' => $this->channelTotals($rideRows, 'cash'),
                'online' => $this->channelTotals($rideRows, 'online'),
                'account' => $this->channelTotals($rideRows, 'account'),
            ],
            'warnings' => $this->dataWarnings($bookings),
        ];
    }

    public function accounts(array $filters = [], int $perPage = 20)
    {
        $query = CompanyAccount::orderBy('id', 'DESC');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('company', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone_no', 'LIKE', "%{$search}%");
            });
        }

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(function ($account) use ($filters) {
            $ledger = $this->accountLedgerRows((int) $account->id, $this->withoutListSearch($filters));

            return [
                'id' => $account->id,
                'name' => $account->name,
                'company' => $account->company,
                'email' => $account->email,
                'phone_no' => $account->phone_no,
                'address' => $account->address,
                'notes' => $account->notes,
                'rides' => $ledger->count(),
                'receivable' => round($ledger->sum('balance_amount'), 2),
                'unbilled' => round($ledger->where('statement_status', 'unbilled')->sum('balance_amount'), 2),
            ];
        });

        return $page;
    }

    public function accountLedger(int $accountId, array $filters = []): array
    {
        $account = CompanyAccount::findOrFail($accountId);
        $rows = $this->accountLedgerRows($accountId, $filters)->values();

        return [
            'account' => $account,
            'rows' => $rows,
            'totals' => [
                'rides' => $rows->count(),
                'gross' => round($rows->sum('fare_amount'), 2),
                'paid' => round($rows->sum('paid_amount'), 2),
                'balance' => round($rows->sum('balance_amount'), 2),
                'unbilled' => round($rows->where('statement_status', 'unbilled')->sum('balance_amount'), 2),
            ],
        ];
    }

    public function rides(array $filters = [], int $perPage = 20)
    {
        $query = $this->bookingQuery($filters)->with(['accountDetail', 'driverDetail'])->orderBy('id', 'DESC');

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn ($booking) => $this->rideFinance($booking));

        return $page;
    }

    public function drivers(array $filters = [], int $perPage = 20)
    {
        $query = CompanyDriver::orderBy('id', 'DESC');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone_no', 'LIKE', "%{$search}%")
                    ->orWhere('plate_no', 'LIKE', "%{$search}%");
            });
        }

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(function ($driver) use ($filters) {
            $rows = $this->driverLedgerRows((int) $driver->id, $this->withoutListSearch($filters));

            return [
                'id' => $driver->id,
                'name' => $driver->name,
                'email' => $driver->email,
                'phone_no' => $driver->phone_no,
                'status' => $driver->status,
                'wallet_balance' => $this->money($driver->wallet_balance),
                'vehicle' => $driver->vehicle_name,
                'plate_no' => $driver->plate_no,
                'rides' => $rows->where('item_type', 'ride')->count(),
                'gross_fares' => round($rows->sum('gross_amount'), 2),
                'company_owes_driver' => round($rows->sum('company_owes_driver'), 2),
                'driver_owes_company' => round($rows->sum('driver_owes_company'), 2),
                'net_amount' => round($rows->sum('net_amount'), 2),
                'packages' => $this->driverPackages((int) $driver->id)->count(),
            ];
        });

        return $page;
    }

    public function driverDetail(int $driverId, array $filters = []): array
    {
        $driver = CompanyDriver::findOrFail($driverId);
        $rows = $this->driverLedgerRows($driverId, $filters)->values();
        $packages = $this->driverPackages($driverId, $filters)->values();
        $wallet = $this->walletRows($driverId, $filters)->values();

        return [
            'driver' => $driver,
            'rows' => $rows,
            'packages' => $packages,
            'wallet' => $wallet,
            'totals' => [
                'rides' => $rows->where('item_type', 'ride')->count(),
                'gross_fares' => round($rows->sum('gross_amount'), 2),
                'company_owes_driver' => round($rows->sum('company_owes_driver'), 2),
                'driver_owes_company' => round($rows->sum('driver_owes_company'), 2),
                'net_amount' => round($rows->sum('net_amount'), 2),
                'package_spend' => round($packages->sum('amount'), 2),
                'wallet_additions' => round($wallet->where('type', 'add')->sum('amount'), 2),
                'wallet_deductions' => round($wallet->where('type', 'deduct')->sum('amount'), 2),
            ],
        ];
    }

    public function payments(array $filters = [], int $perPage = 20)
    {
        $query = FinancePayment::orderBy('id', 'DESC');
        $this->applyPaymentFilters($query, $filters, false);

        return $query->paginate($perPage);
    }

    public function packages(array $filters = [], int $perPage = 20)
    {
        $query = DriverPackage::with([])->orderBy('id', 'DESC');
        $this->applyDateFilters($query, $filters, 'created_at');

        if (!empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $driverIds = CompanyDriver::where('name', 'LIKE', "%{$search}%")
                ->orWhere('email', 'LIKE', "%{$search}%")
                ->orWhere('phone_no', 'LIKE', "%{$search}%")
                ->pluck('id');

            $query->where(function (Builder $inner) use ($search, $driverIds) {
                $inner->where('package_type', 'LIKE', "%{$search}%")
                    ->orWhere('package_top_up_name', 'LIKE', "%{$search}%")
                    ->orWhere('package_top_up_amount', 'LIKE', "%{$search}%")
                    ->orWhereIn('driver_id', $driverIds);
            });
        }

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(function ($package) {
            $driver = CompanyDriver::find($package->driver_id);

            return $this->packageRow($package, $driver);
        });

        return $page;
    }

    public function statements(array $filters = [], int $perPage = 20)
    {
        $query = AccountStatement::with(['items', 'account'])->orderBy('id', 'DESC');
        $this->applyDateFilters($query, $filters, 'period_end');

        if (!empty($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('statement_number', 'LIKE', "%{$search}%")
                    ->orWhere('notes', 'LIKE', "%{$search}%")
                    ->orWhereHas('account', function (Builder $accountQuery) use ($search) {
                        $accountQuery->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('company', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%")
                            ->orWhere('phone_no', 'LIKE', "%{$search}%");
                    });
            });
        }

        return $query->paginate($perPage);
    }

    public function settlements(array $filters = [], int $perPage = 20)
    {
        $query = DriverSettlement::with(['items', 'driver'])->orderBy('id', 'DESC');
        $this->applyDateFilters($query, $filters, 'period_end');

        if (!empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('settlement_number', 'LIKE', "%{$search}%")
                    ->orWhere('notes', 'LIKE', "%{$search}%")
                    ->orWhereHas('driver', function (Builder $driverQuery) use ($search) {
                        $driverQuery->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%")
                            ->orWhere('phone_no', 'LIKE', "%{$search}%")
                            ->orWhere('plate_no', 'LIKE', "%{$search}%");
                    });
            });
        }

        return $query->paginate($perPage);
    }

    public function rideFinance($booking): array
    {
        $fare = $this->effectiveFare($booking);
        $channel = $this->paymentChannel($booking);
        $paid = $this->historicalPaidAmount($booking, $channel);
        $statement = $this->statementStatus((int) $booking->id);
        $settlement = $this->settlementStatus((int) $booking->id);
        $commission = $this->commissionAmount($booking, $fare);
        $completed = $this->isFinancialBooking($booking);
        $companyCollected = 0;
        $driverCashCollected = 0;
        $accountReceivable = 0;
        $companyOwesDriver = 0;
        $driverOwesCompany = 0;

        if ($completed) {
            if ($channel === 'cash') {
                $driverCashCollected = $fare;
                $driverOwesCompany = $commission;
            } elseif ($channel === 'online') {
                $companyCollected = $fare;
                $companyOwesDriver = max($fare - $commission, 0);
            } else {
                $accountReceivable = max($fare - $paid, 0);
                if ($this->settings()->account_driver_payout_timing === 'on_completion' || $paid >= $fare) {
                    $companyOwesDriver = max($fare - $commission, 0);
                }
            }
        }

        return [
            'id' => $booking->id,
            'booking_date' => $booking->booking_date,
            'pickup_time' => $booking->pickup_time,
            'booking_status' => $booking->booking_status,
            'payment_channel' => $channel,
            'payment_status' => $booking->payment_status,
            'account_payment' => $booking->account_payment,
            'passenger_name' => $booking->name,
            'pickup_point' => $booking->pickup_point,
            'destination_point' => $booking->destination_point,
            'distance_meters' => $this->money($booking->distance ?? null),
            'account_id' => $booking->account,
            'account_name' => $booking->accountDetail?->name,
            'driver_id' => $booking->driver,
            'driver_name' => $booking->driverDetail?->name,
            'fare_amount' => round($fare, 2),
            'commission_amount' => round($commission, 2),
            'paid_amount' => round($paid, 2),
            'account_receivable' => round($accountReceivable, 2),
            'company_collected' => round($companyCollected, 2),
            'driver_cash_collected' => round($driverCashCollected, 2),
            'company_owes_driver' => round($companyOwesDriver, 2),
            'driver_owes_company' => round($driverOwesCompany, 2),
            'statement_status' => $statement['status'],
            'statement_id' => $statement['id'],
            'settlement_status' => $settlement['status'],
            'settlement_id' => $settlement['id'],
            'is_receivable' => $accountReceivable > 0,
            'is_settleable' => !empty($booking->driver) && ($companyOwesDriver > 0 || $driverOwesCompany > 0),
        ];
    }

    public function statementItemSnapshot($booking): array
    {
        $finance = $this->rideFinance($booking);

        return [
            'booking_id' => $booking->id,
            'booking_date' => $booking->booking_date,
            'pickup_point' => $booking->pickup_point,
            'destination_point' => $booking->destination_point,
            'driver_name' => $booking->driverDetail?->name,
            'fare_amount' => $finance['fare_amount'],
            'extra_amount' => 0,
            'total_amount' => $finance['fare_amount'],
            'payment_channel' => $finance['payment_channel'],
            'booking_status' => $booking->booking_status,
            'snapshot' => $finance,
        ];
    }

    public function settlementItemSnapshot($booking): array
    {
        $finance = $this->rideFinance($booking);
        $net = $finance['company_owes_driver'] - $finance['driver_owes_company'];

        return [
            'booking_id' => $booking->id,
            'item_date' => $booking->booking_date,
            'item_type' => 'ride',
            'gross_amount' => $finance['fare_amount'],
            'commission_amount' => $finance['commission_amount'],
            'company_owes_driver' => $finance['company_owes_driver'],
            'driver_owes_company' => $finance['driver_owes_company'],
            'net_amount' => round($net, 2),
            'payment_channel' => $finance['payment_channel'],
            'snapshot' => $finance,
        ];
    }

    public function eligibleAccountBookingIds(int $accountId, array $filters = []): Collection
    {
        return $this->bookingQuery($filters)
            ->with(['accountDetail', 'driverDetail'])
            ->where('account', $accountId)
            ->whereNotIn('id', $this->activeStatementBookingIds())
            ->get()
            ->filter(fn ($booking) => $this->rideFinance($booking)['account_receivable'] > 0)
            ->pluck('id')
            ->values();
    }

    public function eligibleDriverBookingIds(int $driverId, array $filters = []): Collection
    {
        return $this->bookingQuery($filters)
            ->with(['accountDetail', 'driverDetail'])
            ->where('driver', $driverId)
            ->whereNotIn('id', $this->activeSettlementBookingIds())
            ->get()
            ->filter(function ($booking) {
                $finance = $this->rideFinance($booking);

                return $finance['company_owes_driver'] > 0 || $finance['driver_owes_company'] > 0;
            })
            ->pluck('id')
            ->values();
    }

    public function number(string $prefix): string
    {
        return strtoupper($prefix) . '-' . now()->format('Ymd-His') . '-' . random_int(100, 999);
    }

    private function bookingQuery(array $filters = []): Builder
    {
        $query = CompanyBooking::query();

        if (!empty($filters['start_date']) || !empty($filters['end_date'])) {
            $this->applyDateFilters($query, $filters, 'booking_date');
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->whereIn('booking_status', $this->bookingStatusAliases($filters['status']));
        }

        if (!empty($filters['payment_channel']) && $filters['payment_channel'] !== 'all') {
            if ($filters['payment_channel'] === 'account') {
                $query->whereNotNull('account')->where('account', '!=', '');
            } else {
                $query->where(function (Builder $inner) {
                    $inner->whereNull('account')->orWhere('account', '');
                })->whereIn('payment_method', $this->paymentMethodAliases($filters['payment_channel']));
            }
        }

        if (!empty($filters['account_id'])) {
            $query->where('account', $filters['account_id']);
        }

        if (!empty($filters['driver_id'])) {
            $query->where('driver', $filters['driver_id']);
        }

        if (!empty($filters['sub_company_id']) && $filters['sub_company_id'] !== 'all') {
            $query->where('sub_company', $filters['sub_company_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('phone_no', 'LIKE', "%{$search}%")
                    ->orWhere('pickup_point', 'LIKE', "%{$search}%")
                    ->orWhere('destination_point', 'LIKE', "%{$search}%")
                    ->orWhere('payment_reference', 'LIKE', "%{$search}%");
            });
        }

        return $query;
    }

    private function accountLedgerRows(int $accountId, array $filters = []): Collection
    {
        return $this->bookingQuery($filters)
            ->with(['accountDetail', 'driverDetail'])
            ->where('account', $accountId)
            ->orderBy('booking_date', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->map(function ($booking) {
                $row = $this->rideFinance($booking);
                $row['balance_amount'] = $row['account_receivable'];

                return $row;
            });
    }

    private function driverLedgerRows(int $driverId, array $filters = []): Collection
    {
        return $this->bookingQuery($filters)
            ->with(['accountDetail', 'driverDetail'])
            ->where('driver', $driverId)
            ->orderBy('booking_date', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->filter(function ($booking) {
                $finance = $this->rideFinance($booking);

                return $finance['company_owes_driver'] > 0 || $finance['driver_owes_company'] > 0;
            })
            ->map(function ($booking) {
                $finance = $this->rideFinance($booking);

                return [
                    'item_type' => 'ride',
                    'booking_id' => $finance['id'],
                    'item_date' => $finance['booking_date'],
                    'passenger_name' => $finance['passenger_name'],
                    'pickup_point' => $finance['pickup_point'],
                    'destination_point' => $finance['destination_point'],
                    'payment_channel' => $finance['payment_channel'],
                    'gross_amount' => $finance['fare_amount'],
                    'commission_amount' => $finance['commission_amount'],
                    'company_owes_driver' => $finance['company_owes_driver'],
                    'driver_owes_company' => $finance['driver_owes_company'],
                    'net_amount' => round($finance['company_owes_driver'] - $finance['driver_owes_company'], 2),
                    'settlement_status' => $finance['settlement_status'],
                    'settlement_id' => $finance['settlement_id'],
                ];
            });
    }

    private function driverPackages(int $driverId, array $filters = []): Collection
    {
        $query = DriverPackage::where('driver_id', $driverId)
            ->orderBy('id', 'DESC');
        $this->applyDateFilters($query, $filters, 'created_at');

        return $query
            ->get()
            ->map(fn ($package) => $this->packageRow($package));
    }

    private function walletRows(int $driverId, array $filters = []): Collection
    {
        $query = WalletTransaction::where('user_type', 'driver')
            ->where('user_id', $driverId)
            ->orderBy('id', 'DESC');
        $this->applyDateFilters($query, $filters, 'created_at');

        return $query
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'date' => optional($row->created_at)->toDateString(),
                'type' => $row->type,
                'amount' => $this->money($row->amount),
                'comment' => $row->comment,
            ]);
    }

    private function packageRow($package, $driver = null): array
    {
        $amount = $this->money($package->package_top_up_amount);
        if ($amount <= 0) {
            $amount = $this->money($package->post_paid_amount);
        }

        return [
            'id' => $package->id,
            'driver_id' => $package->driver_id,
            'driver_name' => $driver?->name,
            'package_type' => $this->normalizePackageType($package->package_type),
            'raw_package_type' => $package->package_type,
            'package_name' => $package->package_top_up_name,
            'pending_rides' => $package->pending_rides,
            'commission_per' => $this->money($package->commission_per),
            'amount' => round($amount, 2),
            'start_date' => $package->start_date,
            'expire_date' => $package->expire_date,
            'created_at' => $package->created_at,
        ];
    }

    private function channelTotals(Collection $rows, string $channel): array
    {
        $filtered = $rows->where('payment_channel', $channel);

        return [
            'rides' => $filtered->count(),
            'gross' => round($filtered->sum('fare_amount'), 2),
            'company_collected' => round($filtered->sum('company_collected'), 2),
            'driver_cash_collected' => round($filtered->sum('driver_cash_collected'), 2),
            'receivable' => round($filtered->sum('account_receivable'), 2),
        ];
    }

    private function applyDateFilters($query, array $filters, string $column): void
    {
        if (!empty($filters['start_date'])) {
            $query->whereDate($column, '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->whereDate($column, '<=', $filters['end_date']);
        }
    }

    private function applyPaymentFilters($query, array $filters, bool $postedOnly = true): void
    {
        $this->applyDateFilters($query, $filters, 'payment_date');

        if ($postedOnly) {
            $query->where('status', 'posted');
        } elseif (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['payment_channel']) && $filters['payment_channel'] !== 'all') {
            $query->where('channel', $filters['payment_channel']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('payment_number', 'LIKE', "%{$search}%")
                    ->orWhere('reference', 'LIKE', "%{$search}%")
                    ->orWhere('notes', 'LIKE', "%{$search}%");
            });
        }
    }

    private function withoutListSearch(array $filters): array
    {
        unset($filters['search']);

        return $filters;
    }

    private function effectiveFare($booking): float
    {
        foreach (['booking_amount', 'offered_amount', 'recommended_amount'] as $field) {
            $amount = $this->money($booking->{$field} ?? null);
            if ($amount > 0) {
                return $amount;
            }
        }

        return 0;
    }

    private function paymentChannel($booking): string
    {
        if (!empty($booking->account)) {
            return 'account';
        }

        $method = strtolower((string) ($booking->payment_method ?? 'cash'));

        if (in_array($method, ['online', 'stripe', 'card'], true)) {
            return 'online';
        }

        return 'cash';
    }

    private function historicalPaidAmount($booking, string $channel): float
    {
        if ($channel !== 'account') {
            return strtolower((string) $booking->payment_status) === 'completed'
                ? $this->effectiveFare($booking)
                : 0;
        }

        $statementItem = AccountStatementItem::where('booking_id', $booking->id)
            ->whereHas('statement', fn ($query) => $query->where('status', '!=', 'void'))
            ->with('statement')
            ->orderBy('id', 'DESC')
            ->first();

        if ($statementItem?->statement) {
            $statement = $statementItem->statement;
            $ratio = (float) $statement->total_amount > 0
                ? min((float) $statement->paid_amount / (float) $statement->total_amount, 1)
                : 0;

            return min($this->effectiveFare($booking), round((float) $statementItem->total_amount * $ratio, 2));
        }

        $financePaid = FinancePayment::where('payer_type', 'account')
            ->where('payer_id', $booking->account)
            ->where('status', 'posted')
            ->where(function (Builder $query) use ($booking) {
                $query->where('meta->booking_id', (string) $booking->id)
                    ->orWhere('meta->booking_id', (int) $booking->id);
            })
            ->sum('amount');

        if ($financePaid > 0) {
            return min($this->money($financePaid), $this->effectiveFare($booking));
        }

        if (strtolower((string) ($booking->account_payment ?? '')) === 'yes') {
            return $this->effectiveFare($booking);
        }

        return 0;
    }

    private function statementStatus(int $bookingId): array
    {
        $item = AccountStatementItem::where('booking_id', $bookingId)
            ->whereHas('statement', fn ($query) => $query->where('status', '!=', 'void'))
            ->with('statement')
            ->orderBy('id', 'DESC')
            ->first();

        return [
            'status' => $item?->statement?->status ?? 'unbilled',
            'id' => $item?->account_statement_id,
        ];
    }

    private function settlementStatus(int $bookingId): array
    {
        $item = DriverSettlementItem::where('booking_id', $bookingId)
            ->whereHas('settlement', fn ($query) => $query->where('status', '!=', 'void'))
            ->with('settlement')
            ->orderBy('id', 'DESC')
            ->first();

        return [
            'status' => $item?->settlement?->status ?? 'unsettled',
            'id' => $item?->driver_settlement_id,
        ];
    }

    private function activeStatementBookingIds(): array
    {
        return AccountStatementItem::whereHas('statement', fn ($query) => $query->where('status', '!=', 'void'))
            ->pluck('booking_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function activeSettlementBookingIds(): array
    {
        return DriverSettlementItem::whereHas('settlement', fn ($query) => $query->where('status', '!=', 'void'))
            ->whereNotNull('booking_id')
            ->pluck('booking_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function commissionAmount($booking, float $fare): float
    {
        $driverPackage = !empty($booking->driver)
            ? DriverPackage::where('driver_id', $booking->driver)->orderBy('id', 'DESC')->first()
            : null;

        $commissionPercent = $this->money($driverPackage?->commission_per);
        if ($commissionPercent <= 0) {
            $settings = CompanySetting::orderBy('id', 'DESC')->first();
            $commissionPercent = $this->money($settings?->package_percentage);
        }

        $financeSettings = $this->settings();
        if ($commissionPercent <= 0) {
            $commissionPercent = $this->money($financeSettings->default_driver_commission_percent);
        }

        $fixed = $this->money($financeSettings->default_driver_commission_fixed);
        $percentAmount = $commissionPercent > 0 ? ($fare * $commissionPercent / 100) : 0;

        return round($percentAmount + $fixed, 2);
    }

    private function isFinancialBooking($booking): bool
    {
        $status = $this->normalizeBookingStatus($booking->booking_status);

        if ($status === 'completed') {
            return true;
        }

        if (in_array($status, ['cancelled', 'canceled', 'no_show'], true)) {
            return $this->effectiveFare($booking) > 0;
        }

        return false;
    }

    private function normalizeBookingStatus(?string $status): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $status)));
    }

    private function bookingStatusAliases(string $status): array
    {
        return match ($this->normalizeBookingStatus($status)) {
            'completed' => ['completed', 'Completed'],
            'cancelled', 'canceled' => ['cancelled', 'Cancelled', 'canceled', 'Canceled'],
            'no_show' => ['no_show', 'No Show', 'no show', 'no-show', 'No-Show'],
            'pending' => ['pending', 'Pending'],
            'started' => ['started', 'Started'],
            'arrived' => ['arrived', 'Arrived'],
            'ongoing' => ['ongoing', 'Ongoing'],
            default => [$status],
        };
    }

    private function paymentMethodAliases(string $channel): array
    {
        return match (strtolower(trim($channel))) {
            'online' => ['online', 'Online', 'stripe', 'Stripe', 'card', 'Card'],
            'cash' => ['cash', 'Cash'],
            default => [$channel],
        };
    }

    private function normalizePackageType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $normalized = strtolower(str_replace([' ', '-'], '_', $type));

        return match ($normalized) {
            'packages_postpaid', 'package_postpaid', 'post_paid', 'postpaid' => 'post_paid',
            'per_ride_commission_topup', 'commission_topup', 'topup', 'top_up' => 'top_up',
            'commission_without_topup', 'commission_without_top_up' => 'commission_without_top_up',
            default => $normalized,
        };
    }

    private function dataWarnings(Collection $bookings): array
    {
        $warnings = [];

        if ($bookings->whereNull('booking_amount')->whereNull('offered_amount')->whereNull('recommended_amount')->count() > 0) {
            $warnings[] = 'Some old rides have no fare amount. They are shown with zero until corrected.';
        }

        if ($bookings->filter(fn ($booking) => $this->normalizeBookingStatus($booking->booking_status) === 'completed' && empty($booking->driver))->count() > 0) {
            $warnings[] = 'Some completed rides do not have a driver assigned, so they cannot be driver-settled.';
        }

        return $warnings;
    }

    private function money($value): float
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($clean) ? round((float) $clean, 2) : 0;
    }
}
