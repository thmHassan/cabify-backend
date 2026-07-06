<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_number')->unique();
            $table->unsignedBigInteger('driver_id');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('gross_fares', 12, 2)->default(0);
            $table->decimal('company_owes_driver', 12, 2)->default(0);
            $table->decimal('driver_owes_company', 12, 2)->default(0);
            $table->decimal('adjustment_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('currency')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('settled_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('driver_id');
            $table->index('status');
            $table->index(['period_start', 'period_end']);
            $table->index(['driver_id', 'status'], 'driver_settlements_driver_status_idx');
            $table->index(['status', 'period_end'], 'driver_settlements_status_period_end_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_settlements');
    }
};
