<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        DB::table('finance_settings')->insert([
            'account_driver_payout_timing' => 'after_account_collection',
            'cash_driver_collection_policy' => 'driver_owes_commission',
            'online_driver_payout_policy' => 'company_owes_driver_net',
            'default_driver_commission_percent' => 0,
            'default_driver_commission_fixed' => 0,
            'stripe_fee_policy' => 'company_cost',
            'statement_prefix' => 'STMT',
            'settlement_prefix' => 'SETTLE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settings');
    }
};
