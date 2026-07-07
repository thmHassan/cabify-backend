<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Support\MapsApi;
use App\Support\TenantDatabaseConfigurator;
use App\Services\MapSearchPreferenceService;
use App\Support\TenantRequestContext;
use App\Models\Tenant as CentralTenant;
use App\Models\CompanySetting;
use App\Models\TenantUser;
use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Models\MobileAppSetting;
use App\Models\PackageSetting;
use Hash;
use App\Models\CompanyUser;
use App\Models\CompanyDriver;
use Illuminate\Support\Facades\Artisan;
use App\Models\CompanyDispatchSystem;
use App\Models\CompanyBooking;
use App\Models\PackageRideCountSetting;
use App\Services\SocketApiUrlResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SettingController extends Controller
{
    public function __construct(
        private readonly MapSearchPreferenceService $mapSearchPreference
    ) {
    }

    public function getCompanyProfile(Request $request)
    {
        try {
            $settings = CompanySetting::orderBy("id", "DESC")->first();
            $data = $settings;

            if (!isset($settings) || $settings == NULL) {
                $settings = (new TenantUser)
                    ->setConnection('central')
                    ->where('id', TenantRequestContext::centralTenantId($request))
                    ->first();

                $data['company_name'] = $settings->data['company_name'];
                $data['company_email'] = $settings->data['email'];
                $data['company_phone_no'] = $settings->data['phone'];
                $data['company_business_license'] = "";
                $data['company_business_address'] = $settings->data['address'];
                $data['company_timezone'] = $settings->data['time_zone'];
                $data['company_description'] = "";
                $data['search_radius'] = CompanySetting::resolveSearchRadiusKm();
                $data['dispatch_timeout'] = CompanySetting::resolveDispatchTimeoutSeconds();
                $data = (object) $data;
            } else {
                $data->search_radius = CompanySetting::resolveSearchRadiusKm($settings);
                $data->dispatch_timeout = CompanySetting::resolveDispatchTimeoutSeconds($settings);
            }
            return response()->json([
                'success' => 1,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getApiKeys(Request $request)
    {
        try {
            $tenantId = TenantRequestContext::centralTenantId($request);

            if (!$tenantId) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Database header or database query parameter is missing.',
                ], 400);
            }

            $tenant = (new CentralTenant)
                ->setConnection('central')
                ->where('id', $tenantId)
                ->first();

            if (!$tenant) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Tenant not found'
                ], 404);
            }

            return response()->json([
                'success' => 1,
                'data' => [
                    'barikoi_api_key' => $tenant->barikoi_api_key,
                    'google_api_key' => $tenant->google_api_key,
                    'maps_api' => $tenant->maps_api,
                    'search_api' => $tenant->search_api,
                    'country_of_use' => $tenant->country_of_use,
                    'units' => $tenant->units,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function saveCompanyProfile(Request $request)
    {
        try {
            $nearestDriverDispatchEnabled = CompanySetting::isNearestDriverDispatchEnabled();

            $rules = [
                'company_name' => 'required|max:255',
                'company_email' => 'required|max:255',
                'company_phone_no' => 'required|max:255',
                'company_business_license' => 'required|max:255',
                'company_business_address' => 'required|max:255',
                'company_timezone' => 'required|max:255',
                'support_contact_no' => 'required|max:255',
                'support_emergency_no' => 'required|max:255',
                'support_rescue_number' => 'required|max:255',
            ];

            if ($request->has('search_radius') && $request->search_radius !== null && $request->search_radius !== '') {
                if (!$nearestDriverDispatchEnabled) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'search_radius can only be updated when auto dispatch nearest driver is enabled',
                    ], 422);
                }

                $rules['search_radius'] = 'required|numeric|min:1';
            }
            if ($request->has('dispatch_timeout') && $request->dispatch_timeout !== null && $request->dispatch_timeout !== '') {
                $rules['dispatch_timeout'] = 'required|integer|min:5|max:300';
            }

            $request->validate($rules);

            $settings = CompanySetting::orderBy("id", "DESC")->first();

            if (!isset($settings) || $settings == NULL) {
                $settings = new CompanySetting;
            }

            $settings->company_name = $request->company_name;
            $settings->company_email = $request->company_email;
            $settings->company_phone_no = $request->company_phone_no;
            $settings->company_business_license = $request->company_business_license;
            $settings->company_business_address = $request->company_business_address;
            $settings->company_timezone = $request->company_timezone;
            $settings->company_description = $request->company_description;
            $settings->support_contact_no = $request->support_contact_no;
            $settings->support_emergency_no = $request->support_emergency_no;
            $settings->support_rescue_number = $request->support_rescue_number;

            if ($nearestDriverDispatchEnabled && $request->has('search_radius') && $request->search_radius !== null && $request->search_radius !== '') {
                $settings->search_radius = $request->search_radius;
            }
            if ($request->has('dispatch_timeout') && $request->dispatch_timeout !== null && $request->dispatch_timeout !== '') {
                $settings->dispatch_timeout = (int) $request->dispatch_timeout;
            }

            $settings->save();

            return response()->json([
                'success' => 1,
                'message' => "Company profile updated successfully"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required',
                'new_password' => 'required',
            ]);

            $tenantId = TenantRequestContext::centralTenantId($request);

            $settings = (new TenantUser)
                ->setConnection('central')
                ->where('id', $tenantId)
                ->first();

            if (!Hash::check($request->current_password, $settings->data['password'])) {
                return response()->json([
                    'error' => 1,
                    'message' => "Current password is mismatched"
                ]);
            }

            $data = (new CentralTenant)
                ->setConnection('central')
                ->where('id', $tenantId)
                ->first();

            $data->password = Hash::make($request->new_password);
            $data->save();

            return response()->json([
                'success' => 1,
                'message' => 'Password updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateMobileSetting(Request $request)
    {
        try {
            $request->validate([
                'keys' => 'required',
            ]);

            foreach ($request->keys as $key => $value) {
                $data = MobileAppSetting::where("key", $key)->first();
                if (!isset($data) || $data == NULL) {
                    $data = new MobileAppSetting;
                    $data->key = $key;
                }
                $data->value = $value;
                $data->save();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Mobile App settings updated successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getMobileSetting(Request $request)
    {
        try {
            $settings = MobileAppSetting::get();

            return response()->json([
                'success' => 1,
                'setting' => $settings
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function saveMainCommission(Request $request)
    {
        try {
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            if (!isset($settings) || $settings == NULL) {
                $settings = new CompanySetting;
            }

            $settings->package_type = $request->package_type;
            $settings->package_days = isset($request->package_days) ? $request->package_days : NULL;
            $settings->package_amount = isset($request->package_amount) ? $request->package_amount : NULL;
            $settings->package_percentage = isset($request->package_percentage) ? $request->package_percentage : NULL;
            $settings->cancellation_per_day = isset($request->cancellation_per_day) ? $request->cancellation_per_day : NULL;
            $settings->waiting_time_charge = isset($request->waiting_time_charge) ? $request->waiting_time_charge : NULL;
            $settings->save();

            return response()->json([
                'success' => 1,
                'message' => 'Commission settings saved successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function savePackageTopup(Request $request)
    {
        try {
            $request->validate([
                'package_name' => 'required',
                'package_type' => 'required',
                'package_duration' => 'required',
                'package_price' => 'required',
            ]);
            $data = new PackageSetting;
            $data->package_name = $request->package_name;
            $data->package_type = $request->package_type;
            $data->package_duration = $request->package_duration;
            $data->package_price = $request->package_price;
            $data->save();

            return response()->json([
                'success' => 1,
                'message' => 'Package Topup saved successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function saveRideCount(Request $request)
    {
        try {
            $request->validate([
                'package_type' => 'required',
                'package_ride_count' => 'required',
                'package_amount' => 'required',
            ]);
            $data = new PackageRideCountSetting;
            $data->package_ride_count = $request->package_ride_count;
            $data->package_amount = $request->package_amount;
            $data->save();

            return response()->json([
                'success' => 1,
                'message' => 'Package Ride Count saved successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function editPackageTopup(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'package_name' => 'required',
                'package_type' => 'required',
                'package_duration' => 'required',
                'package_price' => 'required',
            ]);
            $data = PackageSetting::where("id", $request->id)->first();
            $data->package_name = $request->package_name;
            $data->package_type = $request->package_type;
            $data->package_duration = $request->package_duration;
            $data->package_price = $request->package_price;
            $data->save();

            return response()->json([
                'success' => 1,
                'message' => 'Package Topup updated successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function editRideCount(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'package_type' => 'required',
                'package_ride_count' => 'required',
                'package_amount' => 'required',
            ]);
            
            $data = PackageRideCountSetting::where("id", $request->id)->first();
            $data->package_ride_count = $request->package_ride_count;
            $data->package_amount = $request->package_amount;
            $data->save();

            return response()->json([
                'success' => 1,
                'message' => 'Package Ride Count updated successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getCommissionData(Request $request)
    {
        try {
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            $data['package_type'] = $settings->package_type;
            $data['package_days'] = $settings->package_days;
            $data['package_amount'] = $settings->package_amount;
            $data['package_percentage'] = $settings->package_percentage;
            $data['cancellation_per_day'] = $settings->cancellation_per_day;
            $data['waiting_time_charge'] = $settings->waiting_time_charge;

            $packageTopups = PackageSetting::orderBy("id", "DESC")->get();
            $packageRideCount = PackageRideCountSetting::orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'data' => [
                    'main_commission' => (object) $data,
                    'packageTopups' => $packageTopups,
                    'packageRideCount' => $packageRideCount
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deletePackageTopup(Request $request)
    {
        try {
            $packageTopup = PackageSetting::where("id", $request->id)->first();

            if (isset($packageTopup) && $packageTopup != NULL) {
                $packageTopup->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Package popup deleted successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteRideCount(Request $request)
    {
        try {
            $packageTopup = PackageRideCountSetting::where("id", $request->id)->first();

            if (isset($packageTopup) && $packageTopup != NULL) {
                $packageTopup->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Package ride count deleted successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function planDetail(Request $request)
    {
        try {
            $tenantId = TenantRequestContext::centralTenantId($request);

            $user = (new TenantUser)
                ->setConnection('central')
                ->where('id', $tenantId)
                ->first();

            $subscriptionData = (new Subscription)
                ->setConnection('central')
                ->where("id", $user->data['subscription_type'])
                ->first();

            return response()->json([
                'success' => 1,
                'planDetail' => $subscriptionData
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function paymentHistory(Request $request)
    {
        try {
            $tenantId = TenantRequestContext::centralTenantId($request);

            $transactionHistory = (new UserSubscription)
                ->setConnection('central')
                ->where('user_id', $tenantId)
                ->orderBy("id", "DESC")
                ->get();

            return response()->json([
                'success' => 1,
                'history' => $transactionHistory
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function stripeInformation(Request $request)
    {
        try {
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            return response()->json([
                'success' => 1,
                'settings' => $settings
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function saveStripeInformation(Request $request)
    {
        try {
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            if (!isset($settings) || $settings == NULL) {
                $settings = new CompanySetting;
            }
            $settings->stripe_payment = $request->stripe_payment;
            $settings->driver_app = $request->driver_app;
            $settings->customer_app = $request->customer_app;
            $settings->stripe_secret_key = $request->stripe_secret_key;
            $settings->stripe_key = $request->stripe_key;
            $settings->stripe_webhook_secret = $request->stripe_webhook_secret;
            $settings->stripe_country = $request->stripe_country;
            $settings->save();

            return response()->json([
                'success' => 1,
                'message' => 'Stripe information saved successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function thirdPartyInformation(Request $request)
    {
        try {
            $settings = CompanySetting::orderBy('id', 'DESC')->first();
            $mapProvider = $this->resolveMapProvider($settings, $request);

            if (!$this->isMapProviderConfigured($mapProvider)) {
                return $this->mapProviderUnavailableResponse($mapProvider);
            }

            return response()->json([
                'success' => 1,
                'settings' => $settings,
                'maps_api' => $mapProvider['maps_api'],
                'map_type' => $mapProvider['map_type'],
                'map_provider' => $mapProvider['map_provider'],
                'uses_google_map' => $mapProvider['uses_google_map'],
                'uses_mapify' => $mapProvider['uses_mapify'],
                'google_api_key_configured' => $mapProvider['google_api_key_configured'],
                'google_api_keys' => $mapProvider['uses_google_map'] ? $mapProvider['google_api_key'] : null,
                'mapify_tiles_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-tiles/bright')
                    : null,
                'mapify_tiles_url_template' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-tiles/bright/{z}/{x}/{y}.png')
                    : null,
                'mapify_tiles_auth_query_params' => $mapProvider['uses_mapify']
                    ? ['token']
                    : null,
                'mapify_search_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-search')
                    : null,
                'mapify_geocoding_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-geocoding')
                    : null,
                'mapify_reverse_geocoding_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-reverse-geocoding')
                    : null,
                'map_search_preferences_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/map-search-preferences')
                    : null,
                'map_search_preferences' => $mapProvider['uses_mapify']
                    ? $this->mapSearchPreference->resolve()
                    : null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => 'Unable to load third party information.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function mapInformation(Request $request)
    {
        try {
            $settings = CompanySetting::orderBy('id', 'DESC')->first();
            $mapProvider = $this->resolveMapProvider($settings, $request);
            $centralTenant = $this->resolveCentralTenant($request);

            if (!$this->isMapProviderConfigured($mapProvider)) {
                return $this->mapProviderUnavailableResponse($mapProvider);
            }

            return response()->json([
                'success' => 1,
                'maps_api' => $mapProvider['maps_api'],
                'map_type' => $mapProvider['map_type'],
                'map_provider' => $mapProvider['map_provider'],
                'uses_google_map' => $mapProvider['uses_google_map'],
                'uses_mapify' => $mapProvider['uses_mapify'],
                'google_api_key_configured' => $mapProvider['google_api_key_configured'],
                'central_map_flag' => $centralTenant?->map,
                'central_map_enabled' => ($centralTenant?->map ?? null) === 'enable',
                'mapify_tiles_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-tiles/bright')
                    : null,
                'mapify_tiles_url_template' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-tiles/bright/{z}/{x}/{y}.png')
                    : null,
                'mapify_tiles_auth_query_params' => $mapProvider['uses_mapify']
                    ? ['token']
                    : null,
                'mapify_search_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-search')
                    : null,
                'mapify_geocoding_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-geocoding')
                    : null,
                'mapify_reverse_geocoding_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/mapify-reverse-geocoding')
                    : null,
                'map_search_preferences_endpoint' => $mapProvider['uses_mapify']
                    ? url('/api/company/map-search-preferences')
                    : null,
                'map_search_preferences' => $mapProvider['uses_mapify']
                    ? $this->mapSearchPreference->resolve()
                    : null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => 'Unable to resolve map configuration.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveGoogleMapKeyFromSettings(?CompanySetting $settings): ?string
    {
        $googleMapKey = $settings?->google_api_keys;

        return filled($googleMapKey) ? trim((string) $googleMapKey) : null;
    }

    private function resolveTenantMapsApi(?Request $request): ?string
    {
        if (!$request) {
            return null;
        }

        $tenant = $this->resolveCentralTenant($request);
        if (!$tenant) {
            return null;
        }

        $mapsApi = $tenant->maps_api ?? data_get($tenant->data, 'maps_api');

        return MapsApi::normalize($mapsApi);
    }

    private function resolveCentralTenant(?Request $request): ?CentralTenant
    {
        if (!$request) {
            return null;
        }

        $databaseId = TenantRequestContext::databaseId($request);
        if (!$databaseId) {
            return null;
        }

        return (new CentralTenant)
            ->setConnection('central')
            ->where('id', $databaseId)
            ->first();
    }

    private function resolveMapProvider(?CompanySetting $settings, ?Request $request = null): array
    {
        $googleApiKey = $this->resolveGoogleMapKeyFromSettings($settings);
        $mapsApi = $this->resolveTenantMapsApi($request);
        $mapifyAvailable = filled(config('services.mapify.api_token'));

        if ($mapsApi === MapsApi::GOOGLE) {
            $usesGoogleMap = true;
            $usesMapify = false;
        } elseif (MapsApi::isMapify($mapsApi)) {
            $usesGoogleMap = false;
            $usesMapify = true;
        } else {
            $usesGoogleMap = false;
            $usesMapify = true;
        }

        return [
            'maps_api' => $mapsApi,
            'map_type' => $usesGoogleMap ? MapsApi::GOOGLE : ($usesMapify ? MapsApi::MAPIFY : 'default'),
            'map_provider' => $usesGoogleMap ? MapsApi::GOOGLE : MapsApi::MAPIFY,
            'uses_google_map' => $usesGoogleMap,
            'uses_mapify' => $usesMapify,
            'google_api_key' => $googleApiKey,
            'google_api_key_configured' => filled($googleApiKey),
            'mapify_available' => $mapifyAvailable,
            'mapify_token_configured' => $mapifyAvailable,
        ];
    }

    private function isMapProviderConfigured(array $mapProvider): bool
    {
        if ($mapProvider['uses_google_map']) {
            return $mapProvider['google_api_key_configured'];
        }

        if ($mapProvider['uses_mapify']) {
            return $mapProvider['mapify_available'];
        }

        return false;
    }

    private function mapProviderUnavailableResponse(array $mapProvider): \Illuminate\Http\JsonResponse
    {
        $usesGoogleMap = $mapProvider['uses_google_map'];
        $usesMapify = $mapProvider['uses_mapify'];

        if ($usesGoogleMap) {
            $message = 'Google Maps is enabled for this company, but no Google Maps API key is configured.';
            $hint = 'Add google_api_keys in Third Party settings to use Google Maps.';
        } elseif ($usesMapify) {
            $message = 'Mapify is enabled for this company, but Mapify is not configured on the server.';
            $hint = 'Set MAPIFY_API_TOKEN in server .env, then run: php artisan config:clear && php artisan config:cache';
        } else {
            $message = 'No map provider is configured. Add a Google Maps API key or configure Mapify on the server.';
            $hint = !$mapProvider['mapify_token_configured']
                ? 'Set MAPIFY_API_TOKEN in server .env, then run: php artisan config:clear && php artisan config:cache'
                : 'Add google_api_keys in Third Party settings to use Google Maps.';
        }

        return response()->json([
            'error' => 1,
            'message' => $message,
            'maps_api' => $mapProvider['maps_api'],
            'map_type' => $mapProvider['map_type'],
            'uses_google_map' => $usesGoogleMap,
            'uses_mapify' => $usesMapify,
            'google_api_key_configured' => $mapProvider['google_api_key_configured'],
            'mapify_token_configured' => $mapProvider['mapify_token_configured'],
            'hint' => $hint,
        ], 503);
    }

    public function saveThirdPartyInformation(Request $request)
    {
        try {
            $request->validate([
                'google_api_keys' => 'nullable|string|max:500',
                'barikoi_api_keys' => 'nullable|string|max:500',
                'map_settings' => 'nullable|in:default,custom',
                'mail_server' => 'nullable|string|max:255',
                'mail_from' => 'nullable|string|max:255',
                'mail_user_name' => 'nullable|string|max:255',
                'mail_password' => 'nullable|string|max:255',
                'mail_port' => 'nullable',
            ]);

            $settings = CompanySetting::orderBy('id', 'DESC')->first();

            if (!$settings) {
                $settings = new CompanySetting;
            }

            $googleApiKey = $request->filled('google_api_keys')
                ? trim((string) $request->google_api_keys)
                : null;

            $settings->google_api_keys = $googleApiKey;
            $settings->barikoi_api_keys = $request->barikoi_api_keys;
            $settings->map_settings = filled($googleApiKey) ? 'custom' : ($request->map_settings ?? 'default');
            $settings->mail_server = $request->mail_server;
            $settings->mail_from = $request->mail_from;
            $settings->mail_user_name = $request->mail_user_name;
            $settings->mail_password = $request->mail_password;
            $settings->mail_port = $request->mail_port;
            $settings->save();

            $mapProvider = $this->resolveMapProvider($settings, $request);

            if (!$this->isMapProviderConfigured($mapProvider)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Settings saved, but the selected map provider is not fully configured.',
                    'maps_api' => $mapProvider['maps_api'],
                    'map_type' => $mapProvider['map_type'],
                    'uses_google_map' => $mapProvider['uses_google_map'],
                    'uses_mapify' => $mapProvider['uses_mapify'],
                ], 422);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Third party information saved successfully',
                'maps_api' => $mapProvider['maps_api'],
                'map_type' => $mapProvider['map_type'],
                'map_provider' => $mapProvider['map_provider'],
                'uses_google_map' => $mapProvider['uses_google_map'],
                'uses_mapify' => $mapProvider['uses_mapify'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => 'Unable to save third party information.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function notificationRecipients(Request $request)
    {
        try {
            $request->validate([
                'user_type' => 'required|in:all_users,users,all_drivers,drivers,pending_drivers,approved_drivers,rejected_drivers',
            ]);

            $built = $this->buildNotificationRecipientsQuery(
                $request->user_type,
                $request->vehicle_id,
                $request->search
            );

            if ($built === null) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Invalid user_type',
                ], 422);
            }

            $perPage = $request->perPage ?? $request->per_page ?? 10;
            $paginated = $built['query']->paginate($perPage);
            $paginated->getCollection()->transform(function ($record) use ($built) {
                return $this->formatNotificationRecipient($record, $built['entity_type']);
            });

            return response()->json([
                'success' => 1,
                'recipients' => $paginated,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function sendNotification(Request $request)
    {
        try {
            $request->validate([
                'user_type' => 'required|in:all_users,users,all_drivers,drivers,pending_drivers,approved_drivers,rejected_drivers',
                'title' => 'required|string|max:255',
                'body' => 'required|string|max:1000',
                'vehicle_id' => 'nullable',
                'recipient_id' => 'nullable|integer|min:1',
                'recipient_ids' => 'nullable|array',
                'recipient_ids.*' => 'integer|min:1',
            ]);

            $recipientIds = $this->collectNotificationRecipientIds($request);

            if (empty($recipientIds)) {
                $users = $this->getBroadcastNotificationRecipients($request);
            } else {
                $built = $this->buildNotificationRecipientsQuery(
                    $request->user_type,
                    $request->vehicle_id
                );

                if ($built === null) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Invalid user_type',
                    ], 422);
                }

                $users = $built['query']->whereIn('id', $recipientIds)->get();
                $foundIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();
                $invalidIds = array_values(array_diff($recipientIds, $foundIds));

                if (!empty($invalidIds)) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'One or more recipient IDs are invalid or do not match the selected user type.',
                        'invalid_recipient_ids' => $invalidIds,
                    ], 422);
                }
            }

            if ($users->isEmpty()) {
                return response()->json([
                    'error' => 1,
                    'message' => 'No recipients found for this notification.',
                ], 422);
            }

            $commandUserType = $this->isUserNotificationType($request->user_type) ? 'users' : $request->user_type;

            Artisan::call('app:send-notification', [
                'title' => $request->title,
                'body' => $request->body,
                'users' => $users->pluck('id')->implode(','),
                'user_type' => $commandUserType,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Notification process started successfully',
                'recipient_count' => $users->count(),
                'recipient_ids' => $users->pluck('id')->values()->all(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function isUserNotificationType(?string $userType): bool
    {
        return in_array($userType, ['all_users', 'users'], true);
    }

    private function isDriverNotificationType(?string $userType): bool
    {
        return in_array($userType, [
            'all_drivers',
            'drivers',
            'pending_drivers',
            'approved_drivers',
            'rejected_drivers',
        ], true);
    }

    private function applyDriverNotificationStatusFilter($query, string $userType): void
    {
        if ($userType === 'pending_drivers') {
            $query->where('status', 'pending');
        } elseif ($userType === 'approved_drivers') {
            $query->where('status', 'accepted');
        } elseif ($userType === 'rejected_drivers') {
            $query->where('status', 'rejected');
        }
    }

    private function buildNotificationRecipientsQuery(?string $userType, $vehicleId = null, ?string $search = null): ?array
    {
        if ($this->isUserNotificationType($userType)) {
            $query = CompanyUser::query()->orderBy('id', 'DESC');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('email', 'LIKE', '%' . $search . '%')
                        ->orWhere('phone_no', 'LIKE', '%' . $search . '%');
                });
            }

            return ['query' => $query, 'entity_type' => 'user'];
        }

        if ($this->isDriverNotificationType($userType)) {
            $query = CompanyDriver::query()->orderBy('id', 'DESC');
            $this->applyDriverNotificationStatusFilter($query, $userType);

            if ($vehicleId !== null && $vehicleId !== '') {
                $query->where('vehicle_type', $vehicleId);
            }

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('email', 'LIKE', '%' . $search . '%')
                        ->orWhere('phone_no', 'LIKE', '%' . $search . '%');
                });
            }

            return ['query' => $query, 'entity_type' => 'driver'];
        }

        return null;
    }

    private function formatNotificationRecipient($record, string $entityType): array
    {
        return [
            'id' => $record->id,
            'name' => $record->name,
            'email' => $record->email,
            'phone' => $record->phone_no,
            'country_code' => $record->country_code,
            'status' => $record->status,
            'entity_type' => $entityType,
        ];
    }

    private function collectNotificationRecipientIds(Request $request): array
    {
        $ids = [];

        if ($request->filled('recipient_id')) {
            $ids[] = (int) $request->recipient_id;
        }

        if ($request->filled('recipient_ids')) {
            foreach ((array) $request->recipient_ids as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));
    }

    private function getBroadcastNotificationRecipients(Request $request)
    {
        if ($this->isUserNotificationType($request->user_type)) {
            return CompanyUser::whereNotNull('device_token')->get();
        }

        $query = CompanyDriver::whereNotNull('device_token');
        $this->applyDriverNotificationStatusFilter($query, $request->user_type);

        if ($request->vehicle_id != null) {
            $query->where('vehicle_type', $request->vehicle_id);
        }

        return $query->get();
    }

    public function saveAppContent(Request $request)
    {
        try {
            $setting = CompanySetting::orderBy("id", "DESC")->first();

            if (!isset($setting) || $setting == NULL) {
                $setting = new CompanySetting;
            }
            $setting->terms_conditions = $request->terms_conditions;
            $setting->privacy_policy = $request->privacy_policy;
            $setting->about_us = $request->about_us;
            $setting->save();

            return response()->json([
                'success' => 1,
                'message' => 'App sontent saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getAppContent(Request $request)
    {
        try {
            $setting = CompanySetting::orderBy("id", "DESC")->first();

            return response()->json([
                'success' => 1,
                'data' => $setting
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getCompanyBookingSystem()
    {
        try {
            $setting = CompanySetting::orderBy("id", "DESC")->first();

            $company_booking_system = $setting->company_booking_system;
            $company_admin_dispatch_sytem = $setting->company_admin_dispatch_sytem;

            return response()->json([
                'success' => 1,
                'company_booking_system' => $company_booking_system,
                'company_admin_dispatch_sytem' => $company_admin_dispatch_sytem,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function updateCompanyBookingSystem(Request $request)
    {
        try {
            $setting = CompanySetting::orderBy("id", "DESC")->first();
            $setting->company_booking_system = $request->company_booking_system;
            $setting->save();

            return response()->json([
                'success' => 1,
                'message' => "Booking system updated successfully"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function setDispatchSystem(Request $request)
    {
        try {

            $map = [
                'auto_dispatch_plot_base' => [
                    'immediately_show_on_dispatcher_panel',
                    'show_only_after_not_selected_in_auto_dispatch_first_try',
                    'show_only_after_not_selected_in_auto_dispatch_second_try',
                    'show_only_after_not_selected_in_auto_dispatch_third_try',
                    'put_in_bidding_panel',
                ],

                'bidding_fixed_fare_plot_base' => [
                    'wait_time_seconds',
                    'immediately_show_on_dispatcher_panel',
                    'shows_up_after_first_rejection_or_wait_time_elapsed',
                ],

                'auto_dispatch_nearest_driver' => [
                    'immediately_show_on_dispatcher_panel',
                    'show_only_after_not_selected_in_auto_dispatch_first_try',
                    'show_only_after_not_selected_in_auto_dispatch_second_try',
                    'show_only_after_not_selected_in_auto_dispatch_third_try',
                    'put_in_bidding_panel',
                ],

                'bidding' => [
                    'immediately_show_on_dispatcher_panel',
                    'if_not_received_bid_in_first_10_seconds',
                    'show_only_after_not_selected_in_auto_dispatch_first_try',
                    'show_only_after_not_selected_in_auto_dispatch_second_try',
                    'show_only_after_not_selected_in_auto_dispatch_third_try',
                ],

                'bidding_fixed_fare_nearest_driver' => [
                    'wait_time_seconds',
                    'immediately_show_on_dispatcher_panel',
                    'shows_up_after_first_rejection_or_wait_time_elapsed',
                ],
            ];

            $knownDispatchSystems = array_merge(array_keys($map), ['manual_dispatch_only']);
            $selectedDispatchSystem = $request->input('selected_dispatch_system');
            $selectedDispatchSystem = in_array($selectedDispatchSystem, $knownDispatchSystems, true)
                ? $selectedDispatchSystem
                : null;

            if ($selectedDispatchSystem) {
                CompanyDispatchSystem::where('dispatch_system', '!=', $selectedDispatchSystem)
                    ->update(['status' => 'disable']);
            }

            foreach ($map as $dispatchSystem => $steps) {

                if (!$request->has($dispatchSystem)) {
                    continue;
                }

                $systemIsSelected = !$selectedDispatchSystem || $selectedDispatchSystem === $dispatchSystem;
                $priority = $request->$dispatchSystem['priority'] ?? null;

                foreach ($steps as $step) {
                    CompanyDispatchSystem::where('dispatch_system', $dispatchSystem)
                        ->where('steps', $step)
                        ->update([
                            'status' => $systemIsSelected
                                ? ($request->$dispatchSystem[$step] ?? 'disable')
                                : 'disable',
                            'priority' => $priority,
                        ]);
                }
            }

            if ($request->has('manual_dispatch_only')) {
                $manualIsSelected = !$selectedDispatchSystem || $selectedDispatchSystem === 'manual_dispatch_only';
                CompanyDispatchSystem::where('dispatch_system', 'manual_dispatch_only')
                    ->update([
                        'status' => $manualIsSelected
                            ? $request->manual_dispatch_only['status']
                            : 'disable',
                        'priority' => $request->manual_dispatch_only['priority'],
                    ]);
            }

            if ($request->has('auto_release')) {
                $release = $request->input('auto_release', []);
                $settings = CompanySetting::orderBy('id', 'DESC')->first() ?? new CompanySetting;

                if (array_key_exists('enabled', $release)) {
                    $settings->auto_release_enabled = filter_var($release['enabled'], FILTER_VALIDATE_BOOLEAN);
                }

                if (array_key_exists('lead_minutes', $release)) {
                    $settings->default_release_lead_minutes = max(0, min((int) $release['lead_minutes'], 1440));
                }

                if (array_key_exists('mode', $release)) {
                    $mode = strtolower(trim((string) $release['mode']));
                    $settings->default_release_mode = in_array($mode, CompanySetting::RELEASE_MODES, true)
                        ? $mode
                        : CompanySetting::DEFAULT_RELEASE_MODE;
                }

                $settings->save();
            }

            $tenantId = TenantRequestContext::databaseId($request);
            if ($tenantId) {
                $this->notifyDispatchSettingsChanged($request, $tenantId);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Data updated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyDispatchSettingsChanged(Request $request, string $tenantId): void
    {
        try {
            $body = [
                'client_id' => $tenantId,
                'changed_at' => now()->toISOString(),
            ];

            if ($request->filled('exclude_socket_id')) {
                $body['exclude_socket_id'] = $request->input('exclude_socket_id');
            } elseif ($request->filled('socket_id')) {
                $body['exclude_socket_id'] = $request->input('socket_id');
            }

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
            ])->timeout(5)->post(
                SocketApiUrlResolver::endpoint($request, 'dispatch-settings-changed'),
                $body
            );
        } catch (\Throwable $e) {
            Log::warning('Dispatch settings changed socket call failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getDispatchSystem(Request $request)
    {
        try {
            $data = CompanyDispatchSystem::orderBy("id", "ASC")->get();
            $settings = CompanySetting::orderBy('id', 'DESC')->first();

            return response()->json([
                'success' => 1,
                'data' => $data,
                'release_settings' => CompanySetting::resolveReleaseSettings($settings),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function matchPassword(Request $request)
    {
        try {
            $data = \DB::connection('central')->table('tenants')->where("id", auth('tenant')->user()->id)->first();

            $password = json_decode($data->data)->password;

            if (!Hash::check($request->password, $password)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Invalid Password'
                ]);
            }
            return response()->json([
                'success' => 1,
                'message' => 'Password match successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function dashboard(Request $request)
    {
        try {
            $activeRides = CompanyBooking::where(function ($q) {
                $q->where("booking_status", 'arrived')
                    ->orWhere("booking_status", 'started')
                    ->orWhere("booking_status", 'ongoing');
            })
                ->whereDate("booking_date", date("Y-m-d"))->count();
            $cancelRides = CompanyBooking::where("booking_status", "cancelled")->whereDate("booking_date", date("Y-m-d"))->count();
            $waitingRides = CompanyBooking::where("booking_status", "pending")->whereDate("booking_date", date("Y-m-d"))->count();

            $totalBookings = CompanyBooking::count();
            $scheduledBookings = CompanyBooking::where("booking_status", "pending")->whereDate("booking_date", ">", date("Y-m-d"))->count();
            $completedRides = CompanyBooking::where("booking_status", "completed")->count();
            $totalCancelRides = CompanyBooking::where("booking_status", "cancelled")->count();

            $totalUsers = CompanyUser::count();
            $totalDrivers = CompanyDriver::count();

            $data = [
                "activeRides" => $activeRides,
                "cancelRides" => $cancelRides,
                "waitingRides" => $waitingRides,
                "totalUsers" => $totalUsers,
                "totalDrivers" => $totalDrivers,
                "totalBookings" => $totalBookings,
                "scheduledBookings" => $scheduledBookings,
                "completedRides" => $completedRides,
                "totalCancelRides" => $totalCancelRides,
            ];

            return response()->json([
                'success' => 1,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function systemAlert(Request $request)
    {
        try {
            $tenantId = TenantRequestContext::centralTenantId($request);

            $data = (new TenantUser)
                ->setConnection('central')
                ->where('id', $tenantId)
                ->first();

            $expiryDate = Carbon::parse($data->data['expiry_date']);
            $daysLeft = now()->diffInDays($expiryDate, false);

            $cancelledRide = CompanyBooking::where("booking_status", "cancelled")->whereDate("booking_date", date("Y-m-d"))->count();

            $alerts = [];
            $i = 0;
            if ($daysLeft <= 10) {
                $alerts[$i]['title'] = "Subscription Expiry Alert";
                $alerts[$i]['description'] = "Your Subscription is going to expire in $daysLeft days.";
                $i++;
            }

            $alerts[$i]['title'] = "Cancelled Rides";
            $alerts[$i]['description'] = "Today $cancelledRide rides are canceled";
            $i++;

            $scheduledRide = CompanyBooking::whereDate("booking_date", date("Y-m-d"))->count();
            $alerts[$i]['title'] = "Scheduled Rides";
            $alerts[$i]['description'] = "Today $scheduledRide rides are scheduled";
            $i++;

            return response()->json([
                'success' => 1,
                'alerts' => $alerts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }
    public function getMapsApiCount(Request $request)
    {
        try {
            $request->validate([
                'company_id' => 'required',
            ]);

            $dbName = TenantDatabaseConfigurator::resolveSchemaName((string) $request->company_id);

            if (!$dbName) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Company Id is Invalid. Please contact your Company Admin',
                ], 400);
            }

            Config::set('database.connections.tenant_temp', [
                'driver' => 'mysql',
                'host' => config('database.connections.central.host'),
                'port' => config('database.connections.central.port'),
                'database' => $dbName,
                'username' => config('database.connections.central.username'),
                'password' => config('database.connections.central.password'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
            ]);

            DB::purge('tenant_temp');
            DB::reconnect('tenant_temp');

            $settings = DB::connection('tenant_temp')
                ->table('settings')
                ->orderBy('id', 'DESC')
                ->first();

            return response()->json([
                'success' => 1,
                'maps_api_count' => $settings->maps_api_count ?? 0,
                'last_used' => $settings?->last_use_map_api
                    ? Carbon::parse($settings->last_use_map_api)->format('d M Y, h:i A')
                    : 'Never',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
