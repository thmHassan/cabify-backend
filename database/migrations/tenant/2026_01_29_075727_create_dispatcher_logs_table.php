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
        Schema::create('dispatcher_logs', function (Blueprint $table) {
            $table->id();
            $table->string("dispatcher_id")->nullable();
            $table->datetime("datetime")->nullable();
            $table->enum("type", ['login', 'logout'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispatcher_logs');
    }
};
