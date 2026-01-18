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
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->enum("send_by", ['driver', 'user'])->nullable();
            $table->string("driver_id")->nullable();
            $table->string("user_id")->nullable();
            $table->string("ride_id")->nullable();
            $table->text("message")->nullable();
            $table->enum("status", ['read', 'unread'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
