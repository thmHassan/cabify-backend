<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_scheduled')->default(false)->after('pickup_time');
            $table->enum('pickup_time_type', ['asap', 'time'])->nullable()->after('is_scheduled');
            $table->boolean('dispatch_released')->default(false)->after('pickup_time_type');
            $table->unsignedBigInteger('pending_driver_id')->nullable()->after('driver');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'is_scheduled',
                'pickup_time_type',
                'dispatch_released',
                'pending_driver_id',
            ]);
        });
    }
};
