<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('amount');
            $table->decimal('base_amount', 18, 4)->nullable()->after('currency');
            $table->string('base_currency', 3)->nullable()->after('base_amount');
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('base_currency');
            $table->timestamp('exchange_rate_at')->nullable()->after('exchange_rate');
            $table->string('rate_provider')->nullable()->after('exchange_rate_at');
            $table->string('external_reference')->nullable()->unique()->after('rate_provider');
            $table->timestamp('paid_at')->nullable()->after('external_reference');
        });

        // Existing subscription payments were charged and reported in USD.
        DB::table('transactions')->update([
            'currency' => DB::raw("COALESCE(currency, 'USD')"),
            'base_amount' => DB::raw('COALESCE(base_amount, CAST(amount AS DECIMAL(18, 4)))'),
            'base_currency' => DB::raw("COALESCE(base_currency, 'USD')"),
            'exchange_rate' => DB::raw('COALESCE(exchange_rate, 1)'),
            'exchange_rate_at' => DB::raw('COALESCE(exchange_rate_at, created_at)'),
            'rate_provider' => DB::raw("COALESCE(rate_provider, 'legacy-usd')"),
            'paid_at' => DB::raw('COALESCE(paid_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['external_reference']);
            $table->dropColumn([
                'currency',
                'base_amount',
                'base_currency',
                'exchange_rate',
                'exchange_rate_at',
                'rate_provider',
                'external_reference',
                'paid_at',
            ]);
        });
    }
};
