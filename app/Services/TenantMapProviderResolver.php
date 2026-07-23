<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantMapConfiguration;
use App\Models\TenantProviderCredential;

class TenantMapProviderResolver
{
    public function resolve(string $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $config = TenantMapConfiguration::where('tenant_id', $tenantId)->first();

        if (!$config) {
            $mapProvider = strtolower((string) ($tenant->maps_api ?: 'mapify'));
            $mapProvider = $mapProvider === 'barikoi' ? 'mapify' : $mapProvider;
            $searchProvider = strtolower((string) ($tenant->search_api ?: $mapProvider));

            return [
                'legacy' => true,
                'map_provider' => $mapProvider,
                'search_provider' => $searchProvider,
                'geocoding_provider' => $searchProvider,
                'routing_provider' => $mapProvider === 'google' ? 'google' : 'barikoi',
                'allow_platform_fallback' => true,
                'sources' => ['map' => 'platform', 'search' => 'platform', 'geocoding' => 'platform', 'routing' => 'platform'],
                'credentials' => $this->legacyCredentials($tenant),
            ];
        }

        $credentials = [];
        foreach (TenantProviderCredential::where('tenant_id', $tenantId)->where('status', 'active')->get() as $credential) {
            $credentials[$credential->provider] = ($credential->credentials ?? []) + ['base_url' => $credential->base_url];
        }

        $tenantCredentialProviders = collect([
            [$config->map_provider, $config->map_credential_source],
            [$config->search_provider, $config->search_credential_source],
            [$config->geocoding_provider, $config->geocoding_credential_source],
            [$config->routing_provider, $config->routing_credential_source],
        ])->filter(fn ($item) => $item[1] === 'tenant')->pluck(0)->unique()->all();

        foreach (['google', 'mapify', 'barikoi'] as $provider) {
            if (!isset($credentials[$provider])) {
                $credentials[$provider] = in_array($provider, $tenantCredentialProviders, true)
                    ? []
                    : $this->platformCredentials($provider);
            }
        }

        return [
            'legacy' => false,
            'map_provider' => $config->map_provider,
            'search_provider' => $config->search_provider,
            'geocoding_provider' => $config->geocoding_provider,
            'routing_provider' => $config->routing_provider,
            'allow_platform_fallback' => $config->allow_platform_fallback,
            'sources' => [
                'map' => $config->map_credential_source,
                'search' => $config->search_credential_source,
                'geocoding' => $config->geocoding_credential_source,
                'routing' => $config->routing_credential_source,
            ],
            'credentials' => $credentials,
        ];
    }

    private function legacyCredentials(Tenant $tenant): array
    {
        return [
            'google' => ['server_key' => $tenant->google_api_key ?: Setting::googleMapKey()],
            'barikoi' => ['api_key' => $tenant->barikoi_api_key ?: Setting::barikoiKey()],
            'mapify' => ['api_token' => config('services.mapify.api_token'), 'base_url' => config('services.mapify.base_url')],
        ];
    }

    private function platformCredentials(string $provider): array
    {
        return match ($provider) {
            'google' => ['server_key' => Setting::googleMapKey()],
            'barikoi' => ['api_key' => Setting::barikoiKey()],
            'mapify' => ['api_token' => config('services.mapify.api_token'), 'base_url' => config('services.mapify.base_url')],
            default => [],
        };
    }
}
