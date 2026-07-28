<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('commission_reservation_driver_id')->nullable();
            $table->unsignedBigInteger('commission_reserved_package_id')->nullable();
            $table->string('commission_reserved_type')->nullable();
            $table->decimal('commission_reserved_rate', 12, 2)->nullable();
            $table->decimal('commission_reserved_fixed_amount', 12, 2)->default(0);
            $table->decimal('commission_reserved_amount', 12, 2)->default(0);
            $table->string('commission_reservation_status')->nullable();
            $table->timestamp('commission_reserved_at')->nullable();
            $table->timestamp('commission_reservation_released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'commission_reservation_driver_id',
                'commission_reserved_package_id',
                'commission_reserved_type',
                'commission_reserved_rate',
                'commission_reserved_fixed_amount',
                'commission_reserved_amount',
                'commission_reservation_status',
                'commission_reserved_at',
                'commission_reservation_released_at',
            ]);
        });
    }
};
