<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (!Schema::hasColumn('settings', 'auto_release_enabled')) {
                $table->boolean('auto_release_enabled')->default(true)->after('search_radius');
            }

            if (!Schema::hasColumn('settings', 'default_release_lead_minutes')) {
                $table->unsignedSmallInteger('default_release_lead_minutes')->default(60)->after('auto_release_enabled');
            }

            if (!Schema::hasColumn('settings', 'default_release_mode')) {
                $table->string('default_release_mode', 40)->default('auto_then_bidding')->after('default_release_lead_minutes');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'dispatch_release_at')) {
                $table->timestamp('dispatch_release_at')->nullable()->after('dispatch_released');
            }

            if (!Schema::hasColumn('bookings', 'dispatch_release_mode')) {
                $table->string('dispatch_release_mode', 40)->nullable()->after('dispatch_release_at');
            }

            if (!Schema::hasColumn('bookings', 'dispatch_release_override')) {
                $table->boolean('dispatch_release_override')->default(false)->after('dispatch_release_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['dispatch_release_override', 'dispatch_release_mode', 'dispatch_release_at'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            foreach (['default_release_mode', 'default_release_lead_minutes', 'auto_release_enabled'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
