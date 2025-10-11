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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_name')->nullable();
            $table->enum('front_photo', ['yes', 'no'])->default('no');
            $table->enum('back_photo', ['yes', 'no'])->default('no');
            $table->enum('profile_photo', ['yes', 'no'])->default('no');
            $table->enum('has_issue_date', ['yes', 'no'])->default('no');
            $table->enum('has_expiry_date', ['yes', 'no'])->default('no');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
