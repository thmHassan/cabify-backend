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
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('profile_image_approval_status')->nullable()->after('profile_image');
            $table->text('profile_image_approval_description')->nullable()->after('profile_image_approval_status');
            $table->string('profile_image_pending')->nullable()->after('profile_image_approval_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn([
                'profile_image_approval_status',
                'profile_image_approval_description',
                'profile_image_pending',
            ]);
        });
    }
};
