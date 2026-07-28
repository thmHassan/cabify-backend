<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('users', 'email_verified')) {
                $table->boolean('email_verified')->default(false)->after('email');
            }

            if (!Schema::connection('tenant')->hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email_verified');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('users', 'email_verified')) {
                $table->dropColumn('email_verified');
            }

            if (Schema::connection('tenant')->hasColumn('users', 'email_verified_at')) {
                $table->dropColumn('email_verified_at');
            }
        });
    }
};
