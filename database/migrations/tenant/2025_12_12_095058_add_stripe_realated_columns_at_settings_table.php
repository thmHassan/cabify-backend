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
        Schema::table('settings', function (Blueprint $table) {
            $table->enum("stripe_payment", ['enable', 'disable'])->default('disable');
            $table->enum("driver_app", ['enable', 'disable'])->default('disable');
            $table->enum("customer_app", ['enable', 'disable'])->default('disable');
            $table->string("stripe_secret_key")->nullable();
            $table->string("stripe_key")->nullable();
            $table->string("stripe_webhook_secret")->nullable();
            $table->string("stripe_country")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            //
        });
    }
};
