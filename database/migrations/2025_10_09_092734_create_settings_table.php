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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->text("stripe_secret")->nullable();
            $table->text("stripe_key")->nullable();
            $table->text("barikoi_key")->nullable();
            $table->text("google_map_key")->nullable();
            $table->text("firebase_key")->nullable();
            $table->string("smtp_host")->nullable();
            $table->string("smtp_user_name")->nullable();
            $table->string("smtp_password")->nullable();
            $table->string("smtp_from_address")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
