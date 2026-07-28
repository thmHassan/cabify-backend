<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(
                ['commission_reservation_driver_id', 'commission_reservation_status'],
                'bookings_commission_reservation_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_commission_reservation_lookup');
        });
    }
};
