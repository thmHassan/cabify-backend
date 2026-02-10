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
        Schema::create('driver_packages', function (Blueprint $table) {
            $table->id();
            $table->string("driver_id")->nullable();
            $table->string("package_type")->nullable();
            $table->string("pending_rides")->nullable();
            $table->string("start_date")->nullable();
            $table->string("expire_date")->nullable();
            $table->string("commission_per")->nullable();
            $table->string("post_paid_amount")->nullable();
            $table->string("package_top_up_id")->nullable();
            $table->string("package_top_up_name")->nullable();
            $table->string("package_top_up_amount")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_packages');
    }
};
