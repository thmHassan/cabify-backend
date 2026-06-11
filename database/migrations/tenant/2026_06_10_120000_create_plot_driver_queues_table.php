<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plot_driver_queues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plot_id');
            $table->unsignedBigInteger('driver_id');
            $table->unsignedInteger('rank')->default(1);
            $table->timestamps();

            $table->unique(['plot_id', 'driver_id']);
            $table->index(['plot_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plot_driver_queues');
    }
};
