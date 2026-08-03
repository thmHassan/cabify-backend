<?php

namespace App\Services;

use App\Models\Currency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CurrencyCatalogService
{
    private const CACHE_KEY = 'central_currency_catalog_v1';

    private const FALLBACK = [
        'USD' => ['symbol' => '$', 'decimal_places' => 2, 'symbol_position' => 'before'],
        'EUR' => ['symbol' => '€', 'decimal_places' => 2, 'symbol_position' => 'before'],
        'GBP' => ['symbol' => '£', 'decimal_places' => 2, 'symbol_position' => 'before'],
        'INR' => ['symbol' => '₹', 'decimal_places' => 2, 'symbol_position' => 'before'],
        'CAD' => ['symbol' => 'C$', 'decimal_places' => 2, 'symbol_position' => 'before'],
        'AUD' => ['symbol' => 'A$', 'decimal_places' => 2, 'symbol_position' => 'before'],
        'AED' => ['symbol' => 'د.إ', 'decimal_places' => 2, 'symbol_position' => 'before'],
        'PKR' => ['symbol' => 'Rs', 'decimal_places' => 2, 'symbol_position' => 'before'],
    ];

    public function all(): Collection
    {
        try {
            return Cache::remember(self::CACHE_KEY, now()->addHour(), fn () => Currency::query()
                ->orderBy('sort_order')->orderBy('name')->get());
        } catch (\Throwable $exception) {
            Log::warning('Unable to load the central currency catalog', ['error' => $exception->getMessage()]);
            return collect();
        }
    }

    public function active(): Collection
    {
        return $this->all()->where('is_active', true)->values();
    }

    public function definition(string $code): array
    {
        $code = $this->normalizeCode($code);
        $currency = $this->all()->firstWhere('code', $code);

        if ($currency) {
            return ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => $currency->decimal_places, 'symbol_position' => $currency->symbol_position];
        }

        return ['code' => $code] + (self::FALLBACK[$code] ?? ['symbol' => $code, 'decimal_places' => 2, 'symbol_position' => 'before']);
    }

    public function isActive(string $code): bool
    {
        return $this->active()->contains('code', $this->normalizeCode($code));
    }

    public function format(float|int|string|null $amount, string $code): array
    {
        $definition = $this->definition($code);
        $decimals = max(0, min(4, (int) $definition['decimal_places']));
        $amount = round((float) ($amount ?? 0), $decimals);
        $number = number_format($amount, $decimals, '.', ',');
        $separator = $definition['symbol'] === $definition['code'] ? ' ' : '';
        $formatted = $definition['symbol_position'] === 'after'
            ? $number.' '.$definition['symbol']
            : $definition['symbol'].$separator.$number;

        return ['amount' => $amount, 'currency' => $definition['code'], 'currency_symbol' => $definition['symbol'], 'formatted_amount' => $formatted];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'USD';
    }
}
