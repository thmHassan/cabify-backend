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
            $table->string('vehicle_type_service')->nullable();
            $table->string('recommended_price')->nullable();
            $table->string('minimum_price')->nullable();
            $table->string('minimum_distance')->nullable();
            $table->enum('base_fare_less_than_x_miles_status', ['yes', 'no'])->default('no');
            $table->string('base_fare_less_than_x_miles')->nullable();
            $table->enum('base_fare_less_than_x_price_status', ['yes', 'no'])->default('no');
            $table->string('base_fare_less_than_x_price')->nullable();
            $table->enum('base_fare_from_to_miles_status', ['yes', 'no'])->default('no');
            $table->string('base_fare_from_x_miles')->nullable();
            $table->string('base_fare_to_x_miles')->nullable();
            $table->enum('base_fare_from_to_price_status', ['yes', 'no'])->default('no');
            $table->string('base_fare_from_to_price')->nullable();
            $table->enum('base_fare_greater_than_x_miles_status', ['yes', 'no'])->default('no');
            $table->string('base_fare_greater_than_x_miles')->nullable();
            $table->enum('base_fare_greater_than_x_price_status', ['yes', 'no'])->default('no');
            $table->string('base_fare_greater_than_x_price')->nullable();
            $table->string('first_mile_km')->nullable();
            $table->string('second_mile_km')->nullable();
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
