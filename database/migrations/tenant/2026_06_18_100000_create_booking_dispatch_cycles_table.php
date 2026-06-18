<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_dispatch_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->unique();
            $table->unsignedBigInteger('primary_plot_id')->nullable();
            $table->unsignedBigInteger('current_plot_id')->nullable();
            $table->enum('status', ['in_progress', 'accepted', 'exhausted'])->default('in_progress');
            $table->json('visited_plot_ids')->nullable();
            $table->json('notified_driver_ids')->nullable();
            $table->unsignedInteger('offer_token')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_dispatch_cycles');
    }
};
