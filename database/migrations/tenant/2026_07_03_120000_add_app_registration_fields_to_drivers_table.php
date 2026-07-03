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
            $table->string('gender')->nullable()->after('address');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('city')->nullable()->after('date_of_birth');
            $table->string('country')->nullable()->after('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'date_of_birth',
                'city',
                'country',
            ]);
        });
    }
};
