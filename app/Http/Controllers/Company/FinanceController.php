<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AccountStatement;
use App\Models\AccountStatementItem;
use App\Models\CompanyAccount;
use App\Models\CompanyBooking;
use App\Models\DriverSettlement;
use App\Models\DriverSettlementItem;
use App\Models\FinanceAuditLog;
use App\Models\FinancePayment;
use App\Services\FinanceEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function __construct(private FinanceEngine $finance)
    {
    }

    public function summary(Request $request)
    {
        return response()->json([
            'success' => 1,
            'data' => $this->finance->summary($this->filters($request)),
        ]);
    }

    public function accounts(Request $request)
    {
        return response()->json([
            'success' => 1,
            'list' => $this->finance->accounts($this->filters($request), $this->perPage($request)),
        ]);
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_no' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $account = CompanyAccount::create($data);
        $this->audit('account.created', 'account', $account->id, null, $account->toArray());

        return response()->json([
            'success' => 1,
            'message' => 'Account created successfully',
            'data' => $account,
        ]);
    }

    public function account($id)
    {
        return response()->json([
            'success' => 1,
            'data' => CompanyAccount::findOrFail($id),
        ]);
    }

    public function updateAccount(Request $request, $id)
    {
        $account = CompanyAccount::findOrFail($id);
        $before = $account->toArray();

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_no' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $account->fill($data);
        $account->save();

        $this->audit('account.updated', 'account', $account->id, $before, $account->toArray());

        return response()->json([
            'success' => 1,
            'message' => 'Account updated successfully',
            'data' => $account,
        ]);
    }

    public function accountLedger(Request $request, $id)
    {
        return response()->json([
            'success' => 1,
            'data' => $this->finance->accountLedger((int) $id, $this->filters($request)),
        ]);
    }

    public function payments(Request $request)
    {
        return response()->json([
            'success' => 1,
            'list' => $this->finance->payments($this->filters($request), $this->perPage($request)),
        ]);
    }

    public function storePayment(Request $request)
    {
        $data = $request->validate([
            'payer_type' => 'nullable|string|max:255',
            'payer_id' => 'nullable|integer',
            'receiver_type' => 'nullable|string|max:255',
            'receiver_id' => 'nullable|integer',
            'channel' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|max:20',
            'payment_date' => 'nullable|date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'account_statement_id' => 'nullable|integer',
            'driver_settlement_id' => 'nullable|integer',
            'meta' => 'nullable|array',
        ]);

        $validationError = $this->validateLinkedPaymentTarget($data);
        if ($validationError) {
            return $validationError;
        }

        $payment = FinancePayment::create(array_merge($data, [
            'payment_number' => $this->finance->number('PAY'),
            'currency' => $data['currency'] ?? $this->finance->companyCurrency(),
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'status' => 'posted',
        ]));

        $this->syncStatementPaidAmount($payment->account_statement_id);
        $this->syncSettlementPaidAmount($payment->driver_settlement_id);
        $this->audit('payment.posted', 'finance_payment', $payment->id, null, $payment->toArray());

        return response()->json([
            'success' => 1,
            'message' => 'Payment posted successfully',
            'data' => $payment,
        ]);
    }

    public function rides(Request $request)
    {
        return response()->json([
            'success' => 1,
            'list' => $this->finance->rides($this->filters($request), $this->perPage($request)),
        ]);
    }

    public function drivers(Request $request)
    {
        return response()->json([
            'success' => 1,
            'list' => $this->finance->drivers($this->filters($request), $this->perPage($request)),
        ]);
    }

    public function driverDetail(Request $request, $id)
    {
        return response()->json([
            'success' => 1,
            'data' => $this->finance->driverDetail((int) $id, $this->filters($request)),
        ]);
    }

    public function packages(Request $request)
    {
        return response()->json([
            'success' => 1,
            'list' => $this->finance->packages($this->filters($request), $this->perPage($request)),
        ]);
    }

    public function statements(Request $request)
    {
        return response()->json([
            'success' => 1,
            'list' => $this->finance->statements($this->filters($request), $this->perPage($request)),
        ]);
    }

    public function storeStatement(Request $request)
    {
        $data = $request->validate([
            'account_id' => 'required|integer',
            'booking_ids' => 'nullable|array',
            'booking_ids.*' => 'integer',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date',
            'adjustment_amount' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        CompanyAccount::findOrFail($data['account_id']);

        $bookingIds = collect($data['booking_ids'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($bookingIds->isEmpty()) {
            $bookingIds = $this->finance->eligibleAccountBookingIds((int) $data['account_id'], $this->filters($request));
        }

        $bookings = CompanyBooking::with(['driverDetail', 'accountDetail'])
            ->where('account', $data['account_id'])
            ->whereIn('id', $bookingIds)
            ->whereNotIn('id', AccountStatementItem::whereHas('statement', fn ($query) => $query->where('status', '!=', 'void'))->pluck('booking_id'))
            ->get()
            ->filter(fn ($booking) => $this->finance->rideFinance($booking)['account_receivable'] > 0)
            ->values();

        if ($bookings->isEmpty()) {
            return response()->json([
                'success' => 0,
                'message' => 'No eligible account rides found for this statement',
            ], 422);
        }

        $statement = DB::transaction(function () use ($data, $bookings) {
            $subtotal = 0;
            $statement = AccountStatement::create([
                'statement_number' => $this->finance->number($this->finance->settings()->statement_prefix),
                'account_id' => $data['account_id'],
                'period_start' => $data['period_start'] ?? $bookings->min('booking_date'),
                'period_end' => $data['period_end'] ?? $bookings->max('booking_date'),
                'subtotal' => 0,
                'adjustment_amount' => $data['adjustment_amount'] ?? 0,
                'total_amount' => 0,
                'paid_amount' => 0,
                'balance_amount' => 0,
                'currency' => $this->finance->companyCurrency(),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($bookings as $booking) {
                $snapshot = $this->finance->statementItemSnapshot($booking);
                $subtotal += $snapshot['total_amount'];
                $statement->items()->create($snapshot);
            }

            $total = round($subtotal + (float) ($data['adjustment_amount'] ?? 0), 2);
            $statement->update([
                'subtotal' => round($subtotal, 2),
                'total_amount' => $total,
                'balance_amount' => $total,
            ]);

            return $statement->load(['items', 'account']);
        });

        $this->audit('statement.created', 'account_statement', $statement->id, null, $statement->toArray());

        return response()->json([
            'success' => 1,
            'message' => 'Statement created successfully',
            'data' => $statement,
        ]);
    }

    public function showStatement($id)
    {
        return response()->json([
            'success' => 1,
            'data' => AccountStatement::with(['items', 'account'])->findOrFail($id),
        ]);
    }

    public function sendStatement($id)
    {
        $statement = AccountStatement::with(['items', 'account'])->findOrFail($id);
        $before = $statement->toArray();

        if ($statement->status === 'draft') {
            $statement->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }

        $this->audit('statement.sent', 'account_statement', $statement->id, $before, $statement->fresh()->toArray());

        return response()->json([
            'success' => 1,
            'message' => 'Statement marked as sent',
            'data' => $statement->fresh(['items', 'account']),
        ]);
    }

    public function collectStatement(Request $request, $id)
    {
        $statement = AccountStatement::with(['items', 'account'])->findOrFail($id);
        if ($statement->status === 'void') {
            return response()->json([
                'success' => 0,
                'message' => 'Statement is void and cannot collect payments',
            ], 422);
        }

        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'channel' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $amount = (float) ($data['amount'] ?? $statement->balance_amount);

        if ($amount <= 0) {
            return response()->json([
                'success' => 0,
                'message' => 'Statement has no balance to collect',
            ], 422);
        }

        $payment = FinancePayment::create([
            'payment_number' => $this->finance->number('PAY'),
            'payer_type' => 'account',
            'payer_id' => $statement->account_id,
            'receiver_type' => 'company',
            'receiver_id' => null,
            'channel' => $data['channel'] ?? 'account_collection',
            'amount' => min($amount, (float) $statement->balance_amount),
            'currency' => $statement->currency ?: $this->finance->companyCurrency(),
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'posted',
            'account_statement_id' => $statement->id,
        ]);

        $this->syncStatementPaidAmount($statement->id);
        $this->audit('statement.collected', 'account_statement', $statement->id, null, $payment->toArray());

        return response()->json([
            'success' => 1,
            'message' => 'Collection posted successfully',
            'data' => $statement->fresh(['items', 'account']),
        ]);
    }

    public function voidStatement($id)
    {
        $statement = AccountStatement::findOrFail($id);
        $postedPayments = FinancePayment::where('account_statement_id', $statement->id)
            ->where('status', 'posted')
            ->sum('amount');

        if ((float) $postedPayments > 0) {
            return response()->json([
                'success' => 0,
                'message' => 'Statement has posted payments and cannot be voided',
            ], 422);
        }

        $before = $statement->toArray();
        $statement->update(['status' => 'void']);

        $this->audit('statement.voided', 'account_statement', $statement->id, $before, $statement->fresh()->toArray());

        return response()->json([
            'success' => 1,
            'message' => 'Statement voided successfully',
            'data' => $statement->fresh(['items', 'account']),
        ]);
    }

    public function settlements(Request $request)
    {
        return response()->json([
            'success' => 1,
            'list' => $this->finance->settlements($this->filters($request), $this->perPage($request)),
        ]);
    }

    public function storeSettlement(Request $request)
    {
        $data = $request->validate([
            'driver_id' => 'required|integer',
            'booking_ids' => 'nullable|array',
            'booking_ids.*' => 'integer',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date',
            'adjustment_amount' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        $bookingIds = collect($data['booking_ids'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($bookingIds->isEmpty()) {
            $bookingIds = $this->finance->eligibleDriverBookingIds((int) $data['driver_id'], $this->filters($request));
        }

        $bookings = CompanyBooking::with(['driverDetail', 'accountDetail'])
            ->where('driver', $data['driver_id'])
            ->whereIn('id', $bookingIds)
            ->whereNotIn('id', DriverSettlementItem::whereHas('settlement', fn ($query) => $query->where('status', '!=', 'void'))->pluck('booking_id'))
            ->get()
            ->filter(function ($booking) {
                $finance = $this->finance->rideFinance($booking);

                return $finance['company_owes_driver'] > 0 || $finance['driver_owes_company'] > 0;
            })
            ->values();

        if ($bookings->isEmpty()) {
            return response()->json([
                'success' => 0,
                'message' => 'No eligible driver rides found for this settlement',
            ], 422);
        }

        $settlement = DB::transaction(function () use ($data, $bookings) {
            $settlement = DriverSettlement::create([
                'settlement_number' => $this->finance->number($this->finance->settings()->settlement_prefix),
                'driver_id' => $data['driver_id'],
                'period_start' => $data['period_start'] ?? $bookings->min('booking_date'),
                'period_end' => $data['period_end'] ?? $bookings->max('booking_date'),
                'currency' => $this->finance->companyCurrency(),
                'status' => 'draft',
                'adjustment_amount' => $data['adjustment_amount'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            $totals = [
                'gross_fares' => 0,
                'company_owes_driver' => 0,
                'driver_owes_company' => 0,
                'net_amount' => 0,
            ];

            foreach ($bookings as $booking) {
                $snapshot = $this->finance->settlementItemSnapshot($booking);
                $settlement->items()->create($snapshot);
                $totals['gross_fares'] += $snapshot['gross_amount'];
                $totals['company_owes_driver'] += $snapshot['company_owes_driver'];
                $totals['driver_owes_company'] += $snapshot['driver_owes_company'];
                $totals['net_amount'] += $snapshot['net_amount'];
            }

            $adjustment = (float) ($data['adjustment_amount'] ?? 0);
            $settlement->update([
                'gross_fares' => round($totals['gross_fares'], 2),
                'company_owes_driver' => round($totals['company_owes_driver'], 2),
                'driver_owes_company' => round($totals['driver_owes_company'], 2),
                'net_amount' => round($totals['net_amount'] + $adjustment, 2),
            ]);

            return $settlement->load(['items', 'driver']);
        });

        $this->audit('settlement.created', 'driver_settlement', $settlement->id, null, $settlement->toArray());

        return response()->json([
            'success' => 1,
            'message' => 'Settlement created successfully',
            'data' => $settlement,
        ]);
    }

    public function showSettlement($id)
    {
        return response()->json([
            'success' => 1,
            'data' => DriverSettlement::with(['items', 'driver'])->findOrFail($id),
        ]);
    }

    public function markSettlementSettled(Request $request, $id)
    {
        $settlement = DriverSettlement::findOrFail($id);
        if ($settlement->status === 'void') {
            return response()->json([
                'success' => 0,
                'message' => 'Settlement is void and cannot receive payments',
            ], 422);
        }

        $before = $settlement->toArray();
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'channel' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $targetAmount = abs((float) $settlement->net_amount);
        $alreadyPaid = (float) FinancePayment::where('driver_settlement_id', $settlement->id)
            ->where('status', 'posted')
            ->sum('amount');
        $remaining = max($targetAmount - $alreadyPaid, 0);
        $amount = (float) ($data['amount'] ?? $remaining);

        if ($targetAmount <= 0) {
            $settlement->update([
                'paid_amount' => 0,
                'status' => 'settled',
                'settled_at' => now(),
            ]);
        } elseif ($amount > 0 && $remaining > 0) {
            FinancePayment::create([
                'payment_number' => $this->finance->number('PAY'),
                'payer_type' => $settlement->net_amount >= 0 ? 'company' : 'driver',
                'payer_id' => $settlement->net_amount >= 0 ? null : $settlement->driver_id,
                'receiver_type' => $settlement->net_amount >= 0 ? 'driver' : 'company',
                'receiver_id' => $settlement->net_amount >= 0 ? $settlement->driver_id : null,
                'channel' => $data['channel'] ?? 'driver_settlement',
                'amount' => min($amount, $remaining),
                'currency' => $settlement->currency ?: $this->finance->companyCurrency(),
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'posted',
                'driver_settlement_id' => $settlement->id,
            ]);
        }

        $this->syncSettlementPaidAmount($settlement->id);
        $settlement = $settlement->fresh();

        $this->audit('settlement.settled', 'driver_settlement', $settlement->id, $before, $settlement->fresh()->toArray());

        return response()->json([
            'success' => 1,
            'message' => $settlement->status === 'settled' ? 'Settlement marked as settled' : 'Settlement payment posted',
            'data' => $settlement->fresh(['items', 'driver']),
        ]);
    }

    public function voidSettlement($id)
    {
        $settlement = DriverSettlement::findOrFail($id);
        $postedPayments = FinancePayment::where('driver_settlement_id', $settlement->id)
            ->where('status', 'posted')
            ->sum('amount');

        if ((float) $postedPayments > 0) {
            return response()->json([
                'success' => 0,
                'message' => 'Settlement has posted payments and cannot be voided',
            ], 422);
        }

        $before = $settlement->toArray();
        $settlement->update(['status' => 'void']);

        $this->audit('settlement.voided', 'driver_settlement', $settlement->id, $before, $settlement->fresh()->toArray());

        return response()->json([
            'success' => 1,
            'message' => 'Settlement voided successfully',
            'data' => $settlement->fresh(['items', 'driver']),
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->only([
            'start_date',
            'end_date',
            'search',
            'status',
            'payment_channel',
            'account_id',
            'driver_id',
            'sub_company_id',
        ]);
    }

    private function perPage(Request $request): int
    {
        return max(5, min((int) $request->input('per_page', $request->input('perPage', 20)), 100));
    }

    private function validateLinkedPaymentTarget(array $data)
    {
        if (!empty($data['account_statement_id'])) {
            $statement = AccountStatement::find($data['account_statement_id']);
            if (!$statement || $statement->status === 'void') {
                return response()->json([
                    'success' => 0,
                    'message' => 'Payment cannot be posted to this statement',
                ], 422);
            }

            $posted = (float) FinancePayment::where('account_statement_id', $statement->id)
                ->where('status', 'posted')
                ->sum('amount');
            $remaining = max((float) $statement->total_amount - $posted, 0);

            if ($remaining <= 0 || (float) $data['amount'] > $remaining) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Payment amount exceeds the remaining statement balance',
                ], 422);
            }
        }

        if (!empty($data['driver_settlement_id'])) {
            $settlement = DriverSettlement::find($data['driver_settlement_id']);
            if (!$settlement || $settlement->status === 'void') {
                return response()->json([
                    'success' => 0,
                    'message' => 'Payment cannot be posted to this settlement',
                ], 422);
            }

            $posted = (float) FinancePayment::where('driver_settlement_id', $settlement->id)
                ->where('status', 'posted')
                ->sum('amount');
            $remaining = max(abs((float) $settlement->net_amount) - $posted, 0);

            if ($remaining <= 0 || (float) $data['amount'] > $remaining) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Payment amount exceeds the remaining settlement balance',
                ], 422);
            }
        }

        return null;
    }

    private function syncStatementPaidAmount($statementId): void
    {
        if (!$statementId) {
            return;
        }

        $statement = AccountStatement::find($statementId);
        if (!$statement) {
            return;
        }

        $paid = FinancePayment::where('account_statement_id', $statementId)
            ->where('status', 'posted')
            ->sum('amount');
        $balance = max((float) $statement->total_amount - (float) $paid, 0);

        $statement->update([
            'paid_amount' => round($paid, 2),
            'balance_amount' => round($balance, 2),
            'status' => $balance <= 0 ? 'collected' : ($paid > 0 ? 'partial' : $statement->status),
            'collected_at' => $balance <= 0 ? now() : $statement->collected_at,
        ]);
    }

    private function syncSettlementPaidAmount($settlementId): void
    {
        if (!$settlementId) {
            return;
        }

        $settlement = DriverSettlement::find($settlementId);
        if (!$settlement) {
            return;
        }

        $paid = FinancePayment::where('driver_settlement_id', $settlementId)
            ->where('status', 'posted')
            ->sum('amount');
        $targetAmount = abs((float) $settlement->net_amount);
        $status = $settlement->status;
        $settledAt = $settlement->settled_at;

        if ($settlement->status !== 'void') {
            if ($targetAmount <= 0 || (float) $paid >= $targetAmount) {
                $status = 'settled';
                $settledAt = $settledAt ?: now();
            } elseif ((float) $paid > 0) {
                $status = 'partial';
                $settledAt = null;
            }
        }

        $settlement->update([
            'paid_amount' => round($paid, 2),
            'status' => $status,
            'settled_at' => $settledAt,
        ]);
    }

    private function audit(string $action, ?string $subjectType, $subjectId, ?array $before, ?array $after): void
    {
        FinanceAuditLog::create([
            'actor_type' => 'clientadmin',
            'actor_id' => optional(auth('tenant')->user())->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before_data' => $before,
            'after_data' => $after,
        ]);
    }
}
