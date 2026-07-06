<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_settlement_id');
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

            $table->index('driver_settlement_id');
            $table->index('booking_id');
            $table->index('driver_package_id');
            $table->index(['driver_settlement_id', 'booking_id'], 'driver_settlement_items_doc_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_settlement_items');
    }
};
