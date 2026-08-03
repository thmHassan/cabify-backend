<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Transaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RevenueService
{
    public const BASE_CURRENCY = 'USD';

    public function record(
        string $tenantId,
        float $amount,
        string $currency,
        string $method,
        ?string $externalReference = null,
        ?CarbonInterface $paidAt = null
    ): Transaction {
        $currency = strtoupper(trim($currency)) ?: self::BASE_CURRENCY;
        $paidAt = $paidAt ?: now();

        $snapshot = $this->baseCurrencySnapshot($amount, $currency, $paidAt);
        $attributes = [
            'user_id' => $tenantId,
            'amount' => round($amount, 4),
            'currency' => $currency,
            'base_amount' => $snapshot['amount'],
            'base_currency' => self::BASE_CURRENCY,
            'exchange_rate' => $snapshot['rate'],
            'exchange_rate_at' => $snapshot['recorded_at'],
            'rate_provider' => $snapshot['provider'],
            'paid_at' => $paidAt,
            'status' => 'paid',
            'method' => $method,
        ];

        if ($externalReference) {
            return Transaction::firstOrCreate(
                ['external_reference' => $externalReference],
                $attributes
            );
        }

        return Transaction::create($attributes);
    }

    public function monthlySummary(?iterable $tenantIds = null): array
    {
        $query = Transaction::query()
            ->selectRaw('user_id, currency, SUM(amount) as original_total, SUM(base_amount) as base_total')
            ->selectRaw('SUM(CASE WHEN base_amount IS NULL THEN 1 ELSE 0 END) as missing_base_count')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()]);

        if ($tenantIds !== null) {
            $query->whereIn('user_id', collect($tenantIds)->filter()->values());
        }

        $rows = $query
            ->groupBy('user_id', 'currency')
            ->orderBy('currency')
            ->get();

        $summary = $this->emptySummary();
        $byTenant = [];

        foreach ($rows as $row) {
            $tenantId = (string) $row->user_id;
            $currency = strtoupper((string) ($row->currency ?: self::BASE_CURRENCY));
            $amount = round((float) $row->original_total, 4);
            $baseAmount = round((float) ($row->base_total ?? 0), 4);
            $isComplete = (int) $row->missing_base_count === 0;

            $summary['base_amount'] += $baseAmount;
            $summary['complete'] = $summary['complete'] && $isComplete;
            $this->appendBreakdown($summary['breakdown'], $currency, $amount);

            $byTenant[$tenantId] ??= $this->emptySummary();
            $byTenant[$tenantId]['base_amount'] += $baseAmount;
            $byTenant[$tenantId]['complete'] = $byTenant[$tenantId]['complete'] && $isComplete;
            $this->appendBreakdown($byTenant[$tenantId]['breakdown'], $currency, $amount);
        }

        $summary['base_amount'] = round($summary['base_amount'], 4);
        foreach ($byTenant as &$tenantSummary) {
            $tenantSummary['base_amount'] = round($tenantSummary['base_amount'], 4);
        }

        return [
            'total' => $summary,
            'by_tenant' => $byTenant,
        ];
    }

    private function baseCurrencySnapshot(float $amount, string $currency, CarbonInterface $paidAt): array
    {
        if ($currency === self::BASE_CURRENCY) {
            return [
                'amount' => round($amount, 4),
                'rate' => 1,
                'recorded_at' => $paidAt,
                'provider' => 'identity',
            ];
        }

        try {
            $rateData = Cache::remember(
                "revenue-exchange-rate:{$currency}:" . self::BASE_CURRENCY,
                now()->addHours(6),
                fn () => $this->fetchRate($currency, self::BASE_CURRENCY)
            );

            return [
                'amount' => round($amount * $rateData['rate'], 4),
                'rate' => $rateData['rate'],
                'recorded_at' => $rateData['recorded_at'],
                'provider' => 'exchangerate-api',
            ];
        } catch (\Throwable $exception) {
            Log::error('Revenue exchange-rate snapshot failed', [
                'currency' => $currency,
                'base_currency' => self::BASE_CURRENCY,
                'error' => $exception->getMessage(),
            ]);

            return [
                'amount' => null,
                'rate' => null,
                'recorded_at' => $paidAt,
                'provider' => 'exchangerate-api-unavailable',
            ];
        }
    }

    private function fetchRate(string $from, string $to): array
    {
        $apiKey = Setting::exchangeRateApiKey();
        if (!$apiKey) {
            throw new \RuntimeException('ExchangeRate API key is not configured.');
        }

        $baseUrl = rtrim((string) config('services.exchange_rate.base_url'), '/');
        $response = Http::timeout(15)->retry(2, 250)->get("{$baseUrl}/{$apiKey}/pair/{$from}/{$to}");
        $response->throw();

        $payload = $response->json();
        if (($payload['result'] ?? null) !== 'success' || !isset($payload['conversion_rate'])) {
            throw new \RuntimeException($payload['error-type'] ?? 'Invalid exchange-rate response.');
        }

        $recordedAt = isset($payload['time_last_update_unix'])
            ? Carbon::createFromTimestamp((int) $payload['time_last_update_unix'])
            : now();

        return [
            'rate' => (float) $payload['conversion_rate'],
            'recorded_at' => $recordedAt,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'base_amount' => 0,
            'base_currency' => self::BASE_CURRENCY,
            'breakdown' => [],
            'complete' => true,
        ];
    }

    private function appendBreakdown(array &$breakdown, string $currency, float $amount): void
    {
        foreach ($breakdown as &$item) {
            if ($item['currency'] === $currency) {
                $item['amount'] = round($item['amount'] + $amount, 4);
                return;
            }
        }

        $breakdown[] = [
            'currency' => $currency,
            'amount' => $amount,
        ];
    }
}
