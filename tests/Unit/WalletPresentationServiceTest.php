<?php

namespace Tests\Unit;

use App\Services\CurrencyCatalogService;
use App\Services\WalletPresentationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WalletPresentationServiceTest extends TestCase
{
    #[DataProvider('supportedCurrencies')]
    public function test_it_formats_supported_wallet_currencies(string $currency, string $symbol, string $formatted): void
    {
        $fields = (new WalletPresentationService(new CurrencyCatalogService))->fieldsForCurrency(1234.5, $currency);

        $this->assertSame(1234.5, $fields['wallet_balance']);
        $this->assertSame($currency, $fields['currency']);
        $this->assertSame($symbol, $fields['currency_symbol']);
        $this->assertSame($formatted, $fields['formatted_wallet_balance']);
    }

    public function test_it_uses_the_iso_code_when_a_symbol_is_not_configured(): void
    {
        $fields = (new WalletPresentationService(new CurrencyCatalogService))->fieldsForCurrency(500, 'JPY');

        $this->assertSame('JPY', $fields['currency_symbol']);
        $this->assertSame('JPY 500.00', $fields['formatted_wallet_balance']);
    }

    public static function supportedCurrencies(): array
    {
        return [
            'USD' => ['USD', '$', '$1,234.50'],
            'EUR' => ['EUR', '€', '€1,234.50'],
            'GBP' => ['GBP', '£', '£1,234.50'],
            'INR' => ['INR', '₹', '₹1,234.50'],
            'CAD' => ['CAD', 'C$', 'C$1,234.50'],
            'AUD' => ['AUD', 'A$', 'A$1,234.50'],
        ];
    }
}
