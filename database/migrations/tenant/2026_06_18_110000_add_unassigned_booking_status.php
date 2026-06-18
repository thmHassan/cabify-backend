<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('bookings', 'booking_status')) {
            return;
        }

        DB::statement(
            "ALTER TABLE bookings MODIFY booking_status ENUM(
                'pending',
                'unassigned',
                'started',
                'arrived',
                'ongoing',
                'completed',
                'cancelled'
            ) NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE bookings MODIFY booking_status ENUM(
                'pending',
                'started',
                'arrived',
                'ongoing',
                'completed',
                'cancelled'
            ) NOT NULL DEFAULT 'pending'"
        );
    }
};
