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
        Schema::table('settings', function (Blueprint $table) {
            $table->string("google_api_keys")->nullable();
            $table->string("barikoi_api_keys")->nullable();
            $table->enum("map_settings", ['default', 'custom'])->default('default');
            $table->string("mail_server")->nullable();
            $table->string("mail_from")->nullable();
            $table->string("mail_user_name")->nullable();
            $table->string("mail_password")->nullable();
            $table->string("mail_port")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            //
        });
    }
};
