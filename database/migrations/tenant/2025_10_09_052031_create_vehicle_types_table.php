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
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_type_name')->nullable();
            $table->enum('vehicle_type_service', ['local'])->default('local');
            $table->string('recommended_price')->nullable();
            $table->string('minimum_price')->nullable();
            $table->string('minimum_distance')->nullable();
            $table->string('base_fare_less_than_x_miles')->nullable();
            $table->string('base_fare_less_than_x_price')->nullable();
            $table->string('base_fare_from_x_miles')->nullable();
            $table->string('base_fare_to_x_miles')->nullable();
            $table->string('base_fare_from_to_price')->nullable();
            $table->string('base_fare_greater_than_x_miles')->nullable();
            $table->string('base_fare_greater_than_x_price')->nullable();
            $table->string('first_mile_km')->nullable();
            $table->string('second_mile_km')->nullable();
            $table->string('order_no')->nullable();
            $table->string('vehicle_image')->nullable();
            $table->string('backup_bid_vehicle_type')->nullable();
            $table->enum('base_fare_system_status', ['yes', 'no'])->nullable();
            $table->enum('mileage_system', ['fixed', 'dynamic'])->nullable();
            $table->string('from_array')->nullable();
            $table->string('to_array')->nullable();
            $table->string('price_array')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_types');
    }
};
