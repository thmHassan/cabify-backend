<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers_documents', function (Blueprint $table) {
            $table->timestamp('expiry_reminder_sent_at')->nullable()->after('has_expiry_date');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->timestamp('document_expiry_blocked_at')->nullable();
            $table->string('status_before_document_expiry_block')->nullable();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('expiry_requirement_enabled_at')->nullable();
            $table->unsignedSmallInteger('expiry_grace_days')->default(7);
        });

        DB::table('documents')
            ->where('has_expiry_date', 'yes')
            ->whereNull('expiry_requirement_enabled_at')
            ->update(['expiry_requirement_enabled_at' => now()]);

        Schema::create('driver_document_compliance_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->unsignedBigInteger('document_id');
            $table->date('grace_expires_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['driver_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::table('drivers_documents', function (Blueprint $table) {
            $table->dropColumn('expiry_reminder_sent_at');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn([
                'document_expiry_blocked_at',
                'status_before_document_expiry_block',
            ]);
        });

        Schema::dropIfExists('driver_document_compliance_notices');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'expiry_requirement_enabled_at',
                'expiry_grace_days',
            ]);
        });
    }
};
