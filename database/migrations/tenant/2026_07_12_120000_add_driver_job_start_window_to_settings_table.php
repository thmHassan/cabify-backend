<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (!Schema::hasColumn('settings', 'driver_job_start_window_minutes')) {
                $table->unsignedSmallInteger('driver_job_start_window_minutes')->default(120)->after('default_release_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'driver_job_start_window_minutes')) {
                $table->dropColumn('driver_job_start_window_minutes');
            }
        });
    }
};
