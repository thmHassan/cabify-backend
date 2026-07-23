<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_map_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->string('map_provider')->default('mapify');
            $table->string('search_provider')->default('mapify');
            $table->string('geocoding_provider')->default('mapify');
            $table->string('routing_provider')->default('barikoi');
            $table->string('map_credential_source')->default('platform');
            $table->string('search_credential_source')->default('platform');
            $table->string('geocoding_credential_source')->default('platform');
            $table->string('routing_credential_source')->default('platform');
            $table->boolean('allow_platform_fallback')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_map_configurations');
    }
};
