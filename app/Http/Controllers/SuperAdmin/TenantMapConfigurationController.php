<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMapConfiguration;
use App\Models\TenantProviderCredential;
use App\Services\TenantMapProviderResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantMapConfigurationController extends Controller
{
    public function show(string $tenant, TenantMapProviderResolver $resolver)
    {
        Tenant::findOrFail($tenant);
        $resolved = $resolver->resolve($tenant);

        return response()->json([
            'success' => 1,
            'configuration' => collect($resolved)->except('credentials')->all(),
            'credential_status' => collect($resolved['credentials'])->map(fn ($value) => [
                'configured' => collect($value)->except('base_url')->filter()->isNotEmpty(),
            ]),
        ]);
    }

    public function update(Request $request, string $tenant)
    {
        Tenant::findOrFail($tenant);
        $providers = ['google', 'mapify', 'barikoi'];
        $sources = ['platform', 'tenant'];
        $validated = $request->validate([
            'providers.map.name' => ['required', Rule::in(['google', 'mapify'])],
            'providers.search.name' => ['required', Rule::in($providers)],
            'providers.geocoding.name' => ['required', Rule::in($providers)],
            'providers.routing.name' => ['required', Rule::in($providers)],
            'providers.*.credential_source' => ['required', Rule::in($sources)],
            'allow_platform_fallback' => 'required|boolean',
            'credentials' => 'nullable|array',
            'credentials.google.browser_key' => 'nullable|string|max:1000',
            'credentials.google.server_key' => 'nullable|string|max:1000',
            'credentials.mapify.api_token' => 'nullable|string|max:2000',
            'credentials.mapify.base_url' => 'nullable|url|max:1000',
            'credentials.mapify.routing_url' => 'nullable|url|max:1000',
            'credentials.barikoi.api_key' => 'nullable|string|max:2000',
            'credentials.barikoi.routing_url' => 'nullable|url|max:1000',
        ]);

        DB::connection('central')->transaction(function () use ($tenant, $validated) {
            $p = $validated['providers'];
            TenantMapConfiguration::updateOrCreate(['tenant_id' => $tenant], [
                'map_provider' => $p['map']['name'],
                'search_provider' => $p['search']['name'],
                'geocoding_provider' => $p['geocoding']['name'],
                'routing_provider' => $p['routing']['name'],
                'map_credential_source' => $p['map']['credential_source'],
                'search_credential_source' => $p['search']['credential_source'],
                'geocoding_credential_source' => $p['geocoding']['credential_source'],
                'routing_credential_source' => $p['routing']['credential_source'],
                'allow_platform_fallback' => $validated['allow_platform_fallback'],
            ]);

            foreach (($validated['credentials'] ?? []) as $provider => $credentials) {
                $baseUrl = $credentials['base_url'] ?? null;
                unset($credentials['base_url']);
                if (collect($credentials)->filter()->isEmpty() && !$baseUrl) {
                    continue;
                }
                TenantProviderCredential::updateOrCreate(
                    ['tenant_id' => $tenant, 'provider' => $provider],
                    ['credentials' => $credentials, 'base_url' => $baseUrl, 'status' => 'active']
                );
            }
        });

        return response()->json(['success' => 1, 'message' => 'Tenant map configuration saved successfully.']);
    }
}
