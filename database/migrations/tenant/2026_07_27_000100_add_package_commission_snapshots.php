<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_settings', function (Blueprint $table) {
            $table->string('commission_type')->nullable()->after('package_price');
            $table->decimal('commission_value', 12, 2)->nullable()->after('commission_type');
        });

        Schema::table('driver_packages', function (Blueprint $table) {
            $table->string('commission_type')->nullable()->after('commission_per');
            $table->decimal('commission_value', 12, 2)->nullable()->after('commission_type');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('driver_package_id')->nullable();
            $table->string('commission_type')->nullable();
            $table->decimal('commission_rate', 12, 2)->nullable();
            $table->decimal('commission_amount', 12, 2)->nullable();
            $table->decimal('driver_net_amount', 12, 2)->nullable();
            $table->timestamp('commission_snapshot_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'driver_package_id',
                'commission_type',
                'commission_rate',
                'commission_amount',
                'driver_net_amount',
                'commission_snapshot_at',
            ]);
        });

        Schema::table('driver_packages', function (Blueprint $table) {
            $table->dropColumn(['commission_type', 'commission_value']);
        });

        Schema::table('package_settings', function (Blueprint $table) {
            $table->dropColumn(['commission_type', 'commission_value']);
        });
    }
};
