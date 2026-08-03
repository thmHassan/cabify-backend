<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name', 100);
            $table->string('symbol', 12);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->string('symbol_position', 10)->default('before');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('exchange_enabled')->default(true);
            $table->boolean('stripe_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::connection('central')->table('currencies')->insert([
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'symbol_position' => 'before', 'is_active' => true, 'exchange_enabled' => true, 'stripe_enabled' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'symbol_position' => 'before', 'is_active' => true, 'exchange_enabled' => true, 'stripe_enabled' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimal_places' => 2, 'symbol_position' => 'before', 'is_active' => true, 'exchange_enabled' => true, 'stripe_enabled' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimal_places' => 2, 'symbol_position' => 'before', 'is_active' => true, 'exchange_enabled' => true, 'stripe_enabled' => true, 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'decimal_places' => 2, 'symbol_position' => 'before', 'is_active' => true, 'exchange_enabled' => true, 'stripe_enabled' => true, 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimal_places' => 2, 'symbol_position' => 'before', 'is_active' => true, 'exchange_enabled' => true, 'stripe_enabled' => true, 'sort_order' => 60, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'decimal_places' => 2, 'symbol_position' => 'before', 'is_active' => true, 'exchange_enabled' => true, 'stripe_enabled' => true, 'sort_order' => 70, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'decimal_places' => 2, 'symbol_position' => 'before', 'is_active' => true, 'exchange_enabled' => true, 'stripe_enabled' => true, 'sort_order' => 80, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('currencies');
    }
};
