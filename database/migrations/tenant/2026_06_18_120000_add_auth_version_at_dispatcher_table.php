<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatcher', function (Blueprint $table) {
            $table->unsignedInteger('auth_version')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('dispatcher', function (Blueprint $table) {
            $table->dropColumn('auth_version');
        });
    }
};
