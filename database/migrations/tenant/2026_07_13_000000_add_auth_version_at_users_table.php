<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('users', 'auth_version')) {
                $table->unsignedInteger('auth_version')->default(0)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('users', 'auth_version')) {
                $table->dropColumn('auth_version');
            }
        });
    }
};
