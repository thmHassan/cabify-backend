<?php

use Illuminate\Database\Migrations\Migration;
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
                'cancelled',
                'no_show'
            ) NOT NULL DEFAULT 'pending'"
        );

        DB::table('bookings')
            ->where('booking_status', '')
            ->where('dispatcher_action', 'like', '%no show%')
            ->update(['booking_status' => 'no_show']);
    }

    public function down(): void
    {
        if (!Schema::hasColumn('bookings', 'booking_status')) {
            return;
        }

        DB::table('bookings')
            ->where('booking_status', 'no_show')
            ->update(['booking_status' => 'cancelled']);

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
};
