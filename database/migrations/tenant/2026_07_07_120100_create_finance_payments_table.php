<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->string('payer_type')->nullable();
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->string('receiver_type')->nullable();
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->string('channel')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('posted');
            $table->unsignedBigInteger('account_statement_id')->nullable();
            $table->unsignedBigInteger('driver_settlement_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['payer_type', 'payer_id']);
            $table->index(['receiver_type', 'receiver_id']);
            $table->index('account_statement_id');
            $table->index('driver_settlement_id');
            $table->index('payment_date');
            $table->index(['status', 'payment_date'], 'finance_payments_status_date_idx');
            $table->index(['channel', 'payment_date'], 'finance_payments_channel_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payments');
    }
};
