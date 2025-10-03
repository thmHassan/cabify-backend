<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicle_types', function (Blueprint $table) {
            $table->string("order_no")->nullable();
            $table->string("vehicle_image")->nullable();
            $table->string('backup_bid_vehicle_type')->nullable();
            $table->enum('base_fare_system_status', ['yes', 'no'])->nullable();
            $table->enum('mileage_system', ['fixed', 'dynamic'])->nullable();
            $table->text('from_array')->nullable();
            $table->text('to_array')->nullable();
            $table->text('price_array')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_types', function (Blueprint $table) {
            //
        });
    }
};
