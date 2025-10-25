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
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string("name")->nullable();
            $table->string("email")->nullable();
            $table->string("phone_no")->nullable();
            $table->datetime("package_purchased_date")->nullable();
            $table->string("wallet_balance")->nullable();
            $table->string("sub_company")->nullable();
            $table->string("profile_image")->nullable();
            $table->string("vehicle_name")->nullable();
            $table->string("vehicle_type")->nullable();
            $table->string("vehicle_service")->nullable();
            $table->string("seats")->nullable();
            $table->string("color")->nullable();
            $table->string("capacity")->nullable();
            $table->string("plate_no")->nullable();
            $table->string("vehicle_registration_date")->nullable();
            $table->string("bank_name")->nullable();
            $table->string("bank_account_number")->nullable();
            $table->string("account_holder_name")->nullable();
            $table->string("bank_phone_no")->nullable();
            $table->string("iban_no")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
