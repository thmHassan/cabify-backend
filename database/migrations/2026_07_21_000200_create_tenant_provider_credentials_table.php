<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('provider');
            $table->longText('credentials');
            $table->string('base_url')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_provider_credentials');
    }
};
