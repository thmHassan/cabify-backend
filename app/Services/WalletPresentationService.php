<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletPresentationService
{
    public function __construct(private readonly CurrencyCatalogService $currencies)
    {
    }

    public function fields(float|int|string|null $balance, ?string $tenantId = null): array
    {
        return $this->fieldsForCurrency($balance, $this->companyCurrency($tenantId));
    }

    public function fieldsForCurrency(float|int|string|null $balance, string $currency): array
    {
        $currency = strtoupper(trim($currency));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
        $presentation = $this->currencies->format($balance, $currency);

        return [
            'wallet_balance' => $presentation['amount'],
            'currency' => $presentation['currency'],
            'currency_symbol' => $presentation['currency_symbol'],
            'formatted_wallet_balance' => $presentation['formatted_amount'],
        ];
    }

    public function companyCurrency(?string $tenantId = null): string
    {
        $currency = CompanySetting::query()->orderByDesc('id')->value('company_currency');

        if (! filled($currency) && filled($tenantId)) {
            try {
                $tenant = DB::connection('central')->table('tenants')->select('data')->where('id', $tenantId)->first();
                $tenantData = json_decode((string) ($tenant->data ?? '{}'));
                $currency = $tenantData->currency ?? null;
            } catch (\Throwable $exception) {
                Log::warning('Unable to resolve wallet currency from central tenant data', [
                    'tenant_id' => $tenantId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $currency = strtoupper(trim((string) $currency));
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    public function currencySymbol(string $currency): string
    {
        return $this->currencies->definition($currency)['symbol'];
    }
}
