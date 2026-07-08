<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('bookings', 'reminder_sent_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dateTime('reminder_sent_at')->nullable()->after('reminder_minutes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'reminder_sent_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('reminder_sent_at');
            });
        }
    }
};
