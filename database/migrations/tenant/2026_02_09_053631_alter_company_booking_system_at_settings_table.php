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
        Schema::table('settings', function (Blueprint $table) {
             if (Schema::hasColumn('settings', 'company_booking_system')) {
                $table->dropColumn('company_booking_system');
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->enum("company_booking_system", ['auto_dispatch', 'bidding', 'both'])->nullable("auto_dispatch");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            //
        });
    }
};
