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
        Schema::create('package_settings', function (Blueprint $table) {
            $table->id();
            $table->string("package_name")->nullable();
            $table->string("package_type")->nullable();
            $table->string("package_duration")->nullable();
            $table->string("package_price")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_settings');
    }
};
