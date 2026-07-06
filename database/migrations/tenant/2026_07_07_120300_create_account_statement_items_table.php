<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_statement_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_statement_id');
            $table->unsignedBigInteger('booking_id');
            $table->date('booking_date')->nullable();
            $table->string('pickup_point')->nullable();
            $table->string('destination_point')->nullable();
            $table->string('driver_name')->nullable();
            $table->decimal('fare_amount', 12, 2)->default(0);
            $table->decimal('extra_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_channel')->nullable();
            $table->string('booking_status')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->index('account_statement_id');
            $table->index('booking_id');
            $table->index(['account_statement_id', 'booking_id'], 'account_statement_items_doc_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_statement_items');
    }
};
