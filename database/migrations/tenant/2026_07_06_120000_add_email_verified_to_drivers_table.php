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
            if (!Schema::hasColumn('drivers', 'email_verified')) {
                $table->boolean('email_verified')->default(false)->after('email');
            }

            if (!Schema::hasColumn('drivers', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email_verified');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            if (Schema::hasColumn('drivers', 'email_verified')) {
                $table->dropColumn('email_verified');
            }

            if (Schema::hasColumn('drivers', 'email_verified_at')) {
                $table->dropColumn('email_verified_at');
            }
        });
    }
};
