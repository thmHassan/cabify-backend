<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasTable('currencies') || ! Schema::connection('central')->hasTable('tenants')) {
            return;
        }

        $now = now();
        DB::connection('central')->table('tenants')->select('data')->orderBy('id')->chunk(100, function ($tenants) use ($now) {
            foreach ($tenants as $tenant) {
                $data = json_decode((string) ($tenant->data ?? '{}'), true);
                $code = strtoupper(trim((string) ($data['currency'] ?? '')));

                if (! preg_match('/^[A-Z]{3}$/', $code)) {
                    continue;
                }

                DB::connection('central')->table('currencies')->insertOrIgnore([
                    'code' => $code,
                    'name' => $code,
                    'symbol' => $code,
                    'decimal_places' => 2,
                    'symbol_position' => 'before',
                    'is_active' => true,
                    'exchange_enabled' => true,
                    'stripe_enabled' => true,
                    'sort_order' => 999,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Historical currencies are intentionally retained on rollback.
    }
};
