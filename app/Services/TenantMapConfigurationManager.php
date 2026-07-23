<?php

namespace App\Services;

use App\Models\TenantMapConfiguration;
use App\Models\TenantProviderCredential;
use Illuminate\Http\Request;

class TenantMapConfigurationManager
{
    public function syncFromCompanyRequest(string $tenantId, Request $request): void
    {
        if (!$request->filled('map_provider') && !$request->filled('routing_provider')) {
            return;
        }

        $source = $request->input('credential_source', 'platform');
        TenantMapConfiguration::updateOrCreate(['tenant_id' => $tenantId], [
            'map_provider' => $request->input('map_provider', $request->input('maps_api', 'mapify')),
            'search_provider' => $request->input('search_provider', $request->input('search_api', 'mapify')),
            'geocoding_provider' => $request->input('geocoding_provider', $request->input('search_api', 'mapify')),
            'routing_provider' => $request->input('routing_provider', $request->input('maps_api') === 'google' ? 'google' : 'barikoi'),
            'map_credential_source' => $source,
            'search_credential_source' => $source,
            'geocoding_credential_source' => $source,
            'routing_credential_source' => $source,
            'allow_platform_fallback' => $request->boolean('allow_platform_fallback'),
        ]);

        $credentials = [
            'google' => array_filter(['browser_key' => $request->google_browser_key, 'server_key' => $request->google_server_key]),
            'mapify' => array_filter(['api_token' => $request->mapify_api_token, 'routing_url' => $request->mapify_routing_url]),
            'barikoi' => array_filter(['api_key' => $request->barikoi_api_key]),
        ];

        foreach ($credentials as $provider => $values) {
            if ($values) {
                TenantProviderCredential::updateOrCreate(
                    ['tenant_id' => $tenantId, 'provider' => $provider],
                    ['credentials' => $values, 'status' => 'active']
                );
            }
        }
    }
}
