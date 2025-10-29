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
            $table->string("company_name")->nullable();
            $table->string("company_email")->nullable();
            $table->string("company_phone_no")->nullable();
            $table->string("company_business_license")->nullable();
            $table->string("company_business_address")->nullable();
            $table->string("company_timezone")->nullable();
            $table->string("company_description")->nullable();
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
