<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_dispatch_cycles', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_dispatch_cycles', 'current_driver_id')) {
                $table->unsignedBigInteger('current_driver_id')->nullable()->after('current_plot_id');
            }
            if (!Schema::hasColumn('booking_dispatch_cycles', 'current_driver_rank')) {
                $table->unsignedInteger('current_driver_rank')->nullable()->after('current_driver_id');
            }
            if (!Schema::hasColumn('booking_dispatch_cycles', 'attempted_driver_ids')) {
                $table->json('attempted_driver_ids')->nullable()->after('notified_driver_ids');
            }
            if (!Schema::hasColumn('booking_dispatch_cycles', 'rejected_driver_ids')) {
                $table->json('rejected_driver_ids')->nullable()->after('attempted_driver_ids');
            }
            if (!Schema::hasColumn('booking_dispatch_cycles', 'offer_expires_at')) {
                $table->timestamp('offer_expires_at')->nullable()->after('offer_token');
            }
            if (!Schema::hasColumn('booking_dispatch_cycles', 'fallback_to_bidding')) {
                $table->boolean('fallback_to_bidding')->default(false)->after('offer_expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_dispatch_cycles', function (Blueprint $table) {
            foreach ([
                'current_driver_id',
                'current_driver_rank',
                'attempted_driver_ids',
                'rejected_driver_ids',
                'offer_expires_at',
                'fallback_to_bidding',
            ] as $column) {
                if (Schema::hasColumn('booking_dispatch_cycles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
