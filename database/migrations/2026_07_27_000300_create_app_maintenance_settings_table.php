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
        Schema::connection('central')->create('app_maintenance_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('driver_app', ['enable', 'disable'])->default('enable');
            $table->enum('customer_app', ['enable', 'disable'])->default('enable');
            $table->string('message')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('app_maintenance_settings');
    }
};
