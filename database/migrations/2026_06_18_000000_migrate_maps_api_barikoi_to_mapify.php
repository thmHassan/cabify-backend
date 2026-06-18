<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')->orderBy('id')->chunk(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                $data = json_decode($tenant->data, true);

                if (!is_array($data) || ($data['maps_api'] ?? null) !== 'barikoi') {
                    continue;
                }

                $data['maps_api'] = 'mapify';

                DB::table('tenants')->where('id', $tenant->id)->update([
                    'data' => json_encode($data),
                ]);
            }
        });

        DB::table('onboarding_requests')
            ->where('maps_api', 'barikoi')
            ->update(['maps_api' => 'mapify']);
    }

    public function down(): void
    {
        DB::table('tenants')->orderBy('id')->chunk(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                $data = json_decode($tenant->data, true);

                if (!is_array($data) || ($data['maps_api'] ?? null) !== 'mapify') {
                    continue;
                }

                $data['maps_api'] = 'barikoi';

                DB::table('tenants')->where('id', $tenant->id)->update([
                    'data' => json_encode($data),
                ]);
            }
        });

        DB::table('onboarding_requests')
            ->where('maps_api', 'mapify')
            ->update(['maps_api' => 'barikoi']);
    }
};
