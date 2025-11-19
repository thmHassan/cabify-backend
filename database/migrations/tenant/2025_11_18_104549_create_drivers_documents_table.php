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
        Schema::create('drivers_documents', function (Blueprint $table) {
            $table->id();
            $table->integer('document_id')->nullable();
            $table->integer('driver_id')->nullable();
            $table->string('document_name')->nullable();
            $table->string('front_photo')->nullable();
            $table->string('back_photo')->nullable();
            $table->string('profile_photo')->nullable();
            $table->string('has_issue_date')->nullable();
            $table->string('has_expiry_date')->nullable();
            $table->string('has_number_field')->nullable();
            $table->enum('status', ['pending', 'verified', 'failed'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers_documents');
    }
};
