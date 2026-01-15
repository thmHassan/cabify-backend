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
        Schema::create('dispatch_system', function (Blueprint $table) {
            $table->id();
            $table->enum("dispatch_system", ['auto_dispatch_plot_base', 'bidding_fixed_fare_plot_base', 'auto_dispatch_nearest_driver', 'manual_dispatch_only', 'bidding', 'bidding_fixed_fare_nearest_driver']);
            $table->string("priority")->nullable();
            $table->string("steps")->nullable();
            $table->string("sub_priority")->nullable();
            $table->string("status")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispatch_system');
    }
};
