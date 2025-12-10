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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('sub_company')->nullable();
            $table->enum('multi_booking', ['yes', 'no'])->default('no');
            $table->string('multi_days')->nullable();
            $table->string('pickup_time')->nullable();
            $table->date('booking_date')->nullable();
            $table->enum('booking_type', ['local'])->default('local');
            $table->string('pickup_point')->nullable();
            $table->string('destination_point')->nullable();
            $table->text('via_point')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_no')->nullable();
            $table->string('tel_no')->nullable();
            $table->enum('journey_type', ['one_way', 'return', 'wr'])->default('one_way');
            $table->string('account')->nullable();
            $table->string('vehicle')->nullable();
            $table->string('driver')->nullable();
            $table->string('passenger')->nullable();
            $table->string('luggage')->nullable();
            $table->string('hand_luggage')->nullable();
            $table->string('special_request')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('booking_system')->nullable();
            $table->string('parking_charge')->nullable();
            $table->string('waiting_charge')->nullable();
            $table->string('ac_fares')->nullable();
            $table->string('return_ac_fares')->nullable();
            $table->string('ac_parking_charge')->nullable();
            $table->string('ac_waiting_charge')->nullable();
            $table->string('extra_charge')->nullable();
            $table->string('toll')->nullable();
            $table->enum('booking_status', ['pending', 'ongoing', 'completed', 'cancelled'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
