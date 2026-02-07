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
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('change_vehicle_service')->nullable();
            $table->string('change_vehicle_type')->nullable();
            $table->string('change_color')->nullable();
            $table->string('change_seats')->nullable();
            $table->string('change_plate_no')->nullable();
            $table->string('change_vehicle_registration_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            //
        });
    }
};
