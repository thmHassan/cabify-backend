<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatcher', function (Blueprint $table) {
            $table->boolean('nearby_search_enabled')->default(false)->after('auth_version');
            $table->string('search_boundary_country', 3)->nullable()->after('nearby_search_enabled');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('nearby_search_enabled')->default(false);
            $table->string('search_boundary_country', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispatcher', function (Blueprint $table) {
            $table->dropColumn(['nearby_search_enabled', 'search_boundary_country']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['nearby_search_enabled', 'search_boundary_country']);
        });
    }
};
