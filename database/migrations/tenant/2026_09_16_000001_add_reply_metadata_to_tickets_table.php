<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'reply_by_type')) {
                $table->string('reply_by_type')->nullable()->after('reply_message');
            }

            if (!Schema::hasColumn('tickets', 'reply_by_id')) {
                $table->unsignedBigInteger('reply_by_id')->nullable()->after('reply_by_type');
            }

            if (!Schema::hasColumn('tickets', 'reply_by_name')) {
                $table->string('reply_by_name')->nullable()->after('reply_by_id');
            }

            if (!Schema::hasColumn('tickets', 'replied_at')) {
                $table->timestamp('replied_at')->nullable()->after('reply_by_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            foreach (['replied_at', 'reply_by_name', 'reply_by_id', 'reply_by_type'] as $column) {
                if (Schema::hasColumn('tickets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
