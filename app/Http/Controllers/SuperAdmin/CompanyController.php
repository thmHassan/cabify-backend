<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant; 
use App\Models\Setting; 
use Illuminate\Support\Facades\Artisan;
use DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Subscription;
use App\Models\TenantUser;
use App\Models\CompanySetting;
use App\Models\CompanyDispatcherLog;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Price;
use Stripe\Product;
use Stripe\Webhook;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Subscription as StripeSubscription;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\Rule;
use App\Models\Transaction;
use Carbon\Carbon;
use App\Models\Dispatcher;
use App\Events\NewNotification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use App\Support\MapsApi;
use App\Services\CompanyInactiveService;

class CompanyController extends Controller
{
    public function createCompany(Request $request)
    {
        try{
            $request->validate([
                'company_name' => 'required|max:255',
                'email' => 'required|email|unique:tenants,data->email',
                'password' => 'required|string|min:6',
                'company_admin_name' => 'required|max:255',
                'contact_person' => 'required|max:255',
                'phone' => 'required',
                'address' => 'required|max:255',
                'city' => 'required|max:255',
                'currency' => 'required',
                'maps_api' => ['required', Rule::in(MapsApi::allowedInputValues())],
                'search_api' => 'required',
                'log_map_search_result' => 'required',
                'voip' => 'required',
                'drivers_allowed' => 'required',
                'sub_company' => 'required',
                'passengers_allowed' => 'required',
                'uber_plot_hybrid' => 'required',
                'dispatchers_allowed' => 'required',
                'subscription_type' => 'required',
                'fleet_management' => 'required',
                'sos_features' => 'required',
                'notes' => 'required',
                'stripe_enable' => 'required',
                'units' => 'required',
                'country_of_use' => 'required',
                'time_zone' => 'required',
                'enable_smtp' => 'required',
                'billing_mode' => 'nullable|in:one_time,auto_renew',
                'dispatcher' => 'required',
                'map' => 'required',
                'push_notification' => 'required',
                'usage_monitoring' => 'required',
                'revenue_statements' => 'required',
                'zone' => 'required',
                'manage_zones' => 'required',
                'cms' => 'required',
                'lost_found' => 'required',
                'accounts' => 'required', 
                'picture' => 'required|image|mimes:jpg,jpeg,png,gif|max:2048', 
            ]);

            $count = Tenant::count();

            $tenantId = strtolower(str_replace(' ', '_', $request->company_name)).($count+1);

            $filename = '';
            if(isset($request->picture) && $request->picture != NULL){
                $file = $request->file('picture');
                $filename = 'pictures/'.time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('pictures'), $filename);
            }

            $tenant = new Tenant();
            $tenant->id = $tenantId;
            $tenant->company_name = $request->company_name;
            $tenant->company_admin_name = $request->company_admin_name;
            $tenant->user_name = $request->user_name;
            $tenant->company_id = $request->company_id;
            $tenant->email = $request->email;
            $tenant->contact_person = $request->contact_person;
            $tenant->phone = $request->phone;
            $tenant->address = $request->address;
            $tenant->city = $request->city;
            $tenant->currency = $request->currency;
            $tenant->maps_api = MapsApi::normalize($request->maps_api);
            $tenant->search_api = $request->search_api;
            $tenant->log_map_search_result = $request->log_map_search_result;
            $tenant->voip = $request->voip;
            $tenant->drivers_allowed = $request->drivers_allowed;
            $tenant->sub_company = $request->sub_company;
            $tenant->passengers_allowed = $request->passengers_allowed;
            $tenant->uber_plot_hybrid = $request->uber_plot_hybrid;
            $tenant->dispatchers_allowed = $request->dispatchers_allowed;
            $tenant->subscription_type = $request->subscription_type;
            $tenant->fleet_management = $request->fleet_management;
            $tenant->sos_features = $request->sos_features;
            $tenant->notes = $request->notes;
            $tenant->stripe_enable = $request->stripe_enable;
            $tenant->stripe_enablement = $request->stripe_enablement;
            $tenant->units = $request->units;
            $tenant->country_of_use = $request->country_of_use;
            $tenant->time_zone = $request->time_zone;
            $tenant->enable_smtp = $request->enable_smtp;
            $selectedSubscription = Subscription::where("id", $request->subscription_type)->first();
            $tenant->billing_mode = $selectedSubscription?->deduct_type === 'cash'
                ? 'one_time'
                : ($request->billing_mode ?? 'one_time');
            $tenant->dispatcher = $request->dispatcher;
            $tenant->map = $request->map;
            $tenant->google_api_key = MapsApi::normalize($request->maps_api) === MapsApi::GOOGLE
                ? ($request->google_api_key ?? null)
                : NULL;
            $tenant->barikoi_api_key = (isset($request->map) && $request->map == "enable") ? Setting::barikoiKey() : NULL;
            $tenant->push_notification = $request->push_notification;
            $tenant->usage_monitoring = $request->usage_monitoring;
            $tenant->revenue_statements = $request->revenue_statements;
            $tenant->zone = $request->zone;
            $tenant->manage_zones = $request->manage_zones;
            $tenant->cms = $request->cms;
            $tenant->lost_found = $request->lost_found;
            $tenant->accounts = $request->accounts;
            $tenant->status = 'active';
            $tenant->password = Hash::make($request->password);
            $tenant->picture = $filename ?? null;
            $tenant->payment_status = 'pending';
            // $tenant->database = config('tenancy.database.prefix', 'tenant') . $tenantId . config('tenancy.database.suffix', '');
            // $this->syncTenantData($tenant);
            $tenant->database = 'tenant_' . $tenantId;
            $tenant->save();

            // $tenant->database()->manager()->createDatabase($tenant);
            // $tenant->createDatabase();

            $tenant->run(function () use ($tenant) {
                \Artisan::call('migrate', [
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);

                $system = "bidding";
                if($tenant->uber_plot_hybrid == "auto"){
                    $system = "auto_dispatch";
                }
                elseif($tenant->uber_plot_hybrid == "both"){
                    $system = "both";
                }
                else if($tenant->uber_plot_hybrid == "both"){
                    $system = "bidding";
                }

                \DB::table('settings')->insert([
                    'company_name' => $tenant->company_name,
                    'company_email' => $tenant->email,
                    'company_phone_no' => $tenant->phone,
                    'company_timezone' => $tenant->time_zone,
                    'google_api_keys' => $tenant->google_api_key,
                    'barikoi_api_keys' => $tenant->barikoi_api_key,
                    'company_currency' => $tenant->currency,
                    'company_admin_dispatch_sytem' => $system,
                    'map_settings' => $tenant->enable_smtp == "yes" ? 'default' : 'custom',
                    'stripe_payment' => $tenant->stripe_enable == "yes" ? "enable" : "disable",
                ]);

                DB::table('dispatch_system')->insert([
                    [
                        'dispatch_system' => 'auto_dispatch_plot_base',
                        'priority' => 1,
                        'steps' => 'immediately_show_on_dispatcher_panel',
                        'sub_priority' => 1,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'auto_dispatch_plot_base',
                        'priority' => 1,
                        'steps' => 'show_only_after_not_selected_in_auto_dispatch_first_try',
                        'sub_priority' => 2,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'auto_dispatch_plot_base',
                        'priority' => 1,
                        'steps' => 'show_only_after_not_selected_in_auto_dispatch_second_try',
                        'sub_priority' => 3,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'auto_dispatch_plot_base',
                        'priority' => 1,
                        'steps' => 'show_only_after_not_selected_in_auto_dispatch_third_try',
                        'sub_priority' => 4,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'auto_dispatch_plot_base',
                        'priority' => 1,
                        'steps' => 'put_in_bidding_panel',
                        'sub_priority' => 5,
                        'status' => 'disable',
                    ],

                    [
                        'dispatch_system' => 'bidding_fixed_fare_plot_base',
                        'priority' => 2,
                        'steps' => 'wait_time_seconds',
                        'sub_priority' => 1,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'bidding_fixed_fare_plot_base',
                        'priority' => 2,
                        'steps' => 'immediately_show_on_dispatcher_panel',
                        'sub_priority' => 2,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'bidding_fixed_fare_plot_base',
                        'priority' => 2,
                        'steps' => 'shows_up_after_first_rejection_or_wait_time_elapsed',
                        'sub_priority' => 3,
                        'status' => 'disable',
                    ],

                    [
                        'dispatch_system' => 'auto_dispatch_nearest_driver',
                        'priority' => 3,
                        'steps' => 'immediately_show_on_dispatcher_panel',
                        'sub_priority' => 1,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'auto_dispatch_nearest_driver',
                        'priority' => 3,
                        'steps' => 'show_only_after_not_selected_in_auto_dispatch_first_try',
                        'sub_priority' => 2,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'auto_dispatch_nearest_driver',
                        'priority' => 3,
                        'steps' => 'show_only_after_not_selected_in_auto_dispatch_second_try',
                        'sub_priority' => 3,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'auto_dispatch_nearest_driver',
                        'priority' => 3,
                        'steps' => 'show_only_after_not_selected_in_auto_dispatch_third_try',
                        'sub_priority' => 4,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'auto_dispatch_nearest_driver',
                        'priority' => 3,
                        'steps' => 'put_in_bidding_panel',
                        'sub_priority' => 5,
                        'status' => 'disable',
                    ],

                    [
                        'dispatch_system' => 'manual_dispatch_only',
                        'priority' => 4,
                        'steps' => 'manual_only',
                        'sub_priority' => null,
                        'status' => 'disable',
                    ],

                    [
                        'dispatch_system' => 'bidding',
                        'priority' => 5,
                        'steps' => 'immediately_show_on_dispatcher_panel',
                        'sub_priority' => 1,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'bidding',
                        'priority' => 5,
                        'steps' => 'if_not_received_bid_in_first_10_seconds',
                        'sub_priority' => 2,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'bidding',
                        'priority' => 5,
                        'steps' => 'show_only_after_not_selected_in_auto_dispatch_first_try',
                        'sub_priority' => 3,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'bidding',
                        'priority' => 5,
                        'steps' => 'show_only_after_not_selected_in_auto_dispatch_second_try',
                        'sub_priority' => 4,
                        'status' => 'disable',
                    ],
                    [
                        'dispatch_system' => 'bidding',
                        'priority' => 5,
                        'steps' => 'show_only_after_not_selected_in_auto_dispatch_third_try',
                        'sub_priority' => 5,
                        'status' => 'disable',
                    ],
                ]);

                // $centralDocuments = \DB::connection('central')->table('document_types')->get();

                // foreach ($centralDocuments as $doc) {
                //     \DB::table('documents')->insert([
                //         'document_name' => $doc->document_name,
                //         'front_photo' => $doc->front_photo,
                //         'back_photo' => $doc->back_photo,
                //         'profile_photo' => $doc->profile_photo,
                //         'has_issue_date' => $doc->has_issue_date,
                //         'has_expiry_date' => $doc->has_expiry_date,
                //         'created_at' => now(),
                //         'updated_at' => now()
                //     ]);
                // }

                // $centralDocuments = \DB::connection('central')->table('vehicle_types')->get();

                // foreach ($centralDocuments as $doc) {
                //     \DB::table('vehicle_types')->insert([
                //         'vehicle_type_name' => $doc->vehicle_type_name,
                //         'vehicle_type_service' => $doc->vehicle_type_service,
                //         'recommended_price' => $doc->recommended_price,
                //         'minimum_price' => $doc->minimum_price,
                //         'minimum_distance' => $doc->minimum_distance,
                //         'base_fare_less_than_x_miles' => $doc->base_fare_less_than_x_miles,
                //         'base_fare_less_than_x_price' => $doc->base_fare_less_than_x_price,
                //         'base_fare_from_x_miles' => $doc->base_fare_from_x_miles,
                //         'base_fare_to_x_miles' => $doc->base_fare_to_x_miles,
                //         'base_fare_from_to_price' => $doc->base_fare_from_to_price,
                //         'base_fare_greater_than_x_miles' => $doc->base_fare_greater_than_x_miles,
                //         'base_fare_greater_than_x_price' => $doc->base_fare_greater_than_x_price,
                //         'first_mile_km' => $doc->first_mile_km,
                //         'second_mile_km' => $doc->second_mile_km,
                //         'order_no' => $doc->order_no,
                //         'vehicle_image' => $doc->vehicle_image,
                //         'backup_bid_vehicle_type' => $doc->backup_bid_vehicle_type,
                //         'base_fare_system_status' => $doc->base_fare_system_status,
                //         'mileage_system' => $doc->mileage_system,
                //         'from_array' => $doc->from_array,
                //         'to_array' => $doc->to_array,
                //         'price_array' => $doc->price_array,
                //         'created_at' => now(),
                //         'updated_at' => now()
                //     ]);
                // }


            });

            return response()->json([
                'success' => 1,
                'message' => "Client {$tenant->id} created successfully!",
                'tenant' => $tenant
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function editCompany(Request $request)
    {
        try{
            $request->validate([
                'id' => 'required',
                'company_name' => 'max:255',
                'email' => [
                    'email',
                    Rule::unique('tenants', 'data->email')->ignore($request->id, 'id'),
                ],
                'password' => 'nullable|string|min:6',
                'company_admin_name' => 'max:255',
                'contact_person' => 'max:255',
                'address' => 'max:255',
                'city' => 'max:255',
                'maps_api' => ['nullable', Rule::in(MapsApi::allowedInputValues())],
                'billing_mode' => 'nullable|in:one_time,auto_renew',
            ]);

            $tenant = Tenant::where("id", $request->id)->first();
            $previousStatus = CompanyInactiveService::normalizeStatus($tenant->status ?? 'active');
            $newSubscriptionCreate = 0;

            if($tenant->subscription_type != $request->subscription_type){
                $existingSubscription = Subscription::where("id", $tenant->subscription_type)->first();
                $newSubscription = SUbscription::where("id", $request->subscription_type)->first();
                $hasStripeSubscription = !empty($tenant->stripe_subscription_id)
                    && str_starts_with((string) $tenant->stripe_subscription_id, 'sub_');

                if($newSubscription->deduct_type == "cash"){
                    if($existingSubscription->deduct_type == "card" && $hasStripeSubscription){
                        Stripe::setApiKey(Setting::stripeSecret());

                        StripeSubscription::update(
                            $tenant->stripe_subscription_id,
                            ['cancel_at_period_end' => true]
                        );
                    }
                }
                elseif($newSubscription->deduct_type == "card"){
                    if($existingSubscription->deduct_type == "card" && $hasStripeSubscription){
                        Stripe::setApiKey(Setting::stripeSecret());

                        StripeSubscription::update(
                            $tenant->stripe_subscription_id,
                            ['cancel_at_period_end' => true]
                        );

                        $currentSubscription = \Stripe\Subscription::retrieve(
                            $tenant->stripe_subscription_id
                        );

                        $products = Product::all(['limit' => 100]);
                        $existing = collect($products->data)->firstWhere('name', $newSubscription->id);

                        if($existing){
                            $productId = $existing->id;
                        }
                        else{
                            $product = Product::create([
                                'name' => $newSubscription->id,
                                'description' => $newSubscription->plan_name . ", ". $newSubscription->billing_cycle. ", ". $newSubscription->amount .", ". $newSubscription->features,
                            ]);
                            $productId = $product->id;
                        }

                        $existingPrice = Price::all([
                            'limit' => 100,
                            'product' => $productId,
                        ]);

                        $interval = "month";
                        if($newSubscription->billing_cycle == "monthly"){
                            $interval = "month";
                        }
                        elseif($newSubscription->billing_cycle == "yearly"){
                            $interval = "year";
                        }

                        $matching = collect($existingPrice->data)->firstWhere(fn($p) =>
                            $p->unit_amount == ($newSubscription->amount * 100) && $p->recurring->interval == $interval
                        );

                        if ($matching) {
                            $priceId = $matching->id;
                        } else {
                            $price = Price::create([
                                'unit_amount' => ($newSubscription->amount * 100),
                                'currency' => 'usd',
                                'recurring' => ['interval' => $interval],
                                'product' => $productId,
                            ]);
                            $priceId = $price->id;
                        }

                        $newStripeSubscription = \Stripe\Subscription::create([
                            'customer' => $tenant->stripe_customer_id,
                            'items' => [
                                ['price' => $priceId],
                            ],
                            'trial_end' => $currentSubscription->current_period_end,
                        ]);

                        $tenant->stripe_subscription_id = $newStripeSubscription->id;
                        $tenant->save();
                    }
                    elseif($existingSubscription->deduct_type == "cash" || !$hasStripeSubscription){
                        $newSubscriptionCreate = 1;                        
                    }
                }
            }

            $tenant->company_name = isset($request->company_name) ? $request->company_name : $tenant->company_name;
            $tenant->company_admin_name = isset($request->company_admin_name) ? $request->company_admin_name : $tenant->company_admin_name;
            $tenant->user_name = isset($request->user_name) ? $request->user_name : $tenant->user_name;
            $tenant->company_id = isset($request->company_id) ? $request->company_id : $tenant->company_id;
            $tenant->email = isset($request->email) ? $request->email : $tenant->email;
            $tenant->contact_person = isset($request->contact_person) ? $request->contact_person : $tenant->contact_person;
            $tenant->phone = isset($request->phone) ? $request->phone : $tenant->phone;
            $tenant->address = isset($request->address) ? $request->address : $address->address;
            $tenant->city = isset($request->city) ? $request->city : $tenant->city;
            $tenant->currency = isset($request->currency) ? $request->currency : $tenant->currency;
            $tenant->maps_api = isset($request->maps_api)
                ? MapsApi::normalize($request->maps_api)
                : MapsApi::normalize($tenant->maps_api);
            $tenant->google_api_key = isset($request->google_api_key) ? $request->google_api_key : $tenant->google_api_key;
            $tenant->barikoi_api_key = isset($request->barikoi_api_key) ? $request->barikoi_api_key : $tenant->barikoi_api_key;
            $tenant->search_api = isset($request->search_api) ? $request->search_api : $tenant->search_api;
            $tenant->log_map_search_result = isset($request->log_map_search_result) ? $request->log_map_search_result : $tenant->log_map_search_result;
            $tenant->voip = isset($request->voip) ? $request->voip : $tenant->voip;
            $tenant->drivers_allowed = isset($request->drivers_allowed) ? $request->drivers_allowed : $tenant->drivers_allowed;
            $tenant->sub_company = isset($request->sub_company) ? $request->sub_company : $tenant->sub_company;
            $tenant->passengers_allowed = isset($request->passengers_allowed) ? $request->passengers_allowed : $tenant->passengers_allowed;
            $tenant->uber_plot_hybrid = isset($request->uber_plot_hybrid) ? $request->uber_plot_hybrid : $tenant->uber_plot_hybrid;
            $tenant->dispatchers_allowed = isset($request->dispatchers_allowed) ? $request->dispatchers_allowed : $tenant->dispatchers_allowed;
            $tenant->subscription_type = isset($request->subscription_type) ? $request->subscription_type : $tenant->subscription_type;
            $tenant->fleet_management = isset($request->fleet_management) ? $request->fleet_management : $tenant->fleet_management;
            $tenant->sos_features = isset($request->sos_features) ? $request->sos_features : $tenant->sos_features;
            $tenant->notes = isset($request->notes) ? $request->notes : $tenant->notes;
            $tenant->stripe_enable = isset($request->stripe_enable) ? $request->stripe_enable : $tenant->stripe_enable;
            $tenant->stripe_enablement = isset($request->stripe_enablement) ? $request->stripe_enablement : $tenant->stripe_enablement;
            $tenant->units = isset($request->units) ? $request->units : $tenant->units;
            $tenant->country_of_use = isset($request->country_of_use) ? $request->country_of_use : $tenant->country_of_use;
            $tenant->time_zone = isset($request->time_zone) ? $request->time_zone : $tenant->time_zone;
            $tenant->enable_smtp = isset($request->enable_smtp) ? $request->enable_smtp : $tenant->enable_smtp;
            $selectedSubscription = Subscription::where("id", $tenant->subscription_type)->first();
            $tenant->billing_mode = $selectedSubscription?->deduct_type === 'cash'
                ? 'one_time'
                : (isset($request->billing_mode) ? $request->billing_mode : ($tenant->billing_mode ?? 'one_time'));
            $tenant->dispatcher = isset($request->dispatcher) ? $request->dispatcher : $tenant->dispatcher;
            $tenant->map = isset($request->map) ? $request->map : $tenant->map;
            $tenant->push_notification = isset($request->push_notification) ? $request->push_notification : $tenant->push_notification;
            $tenant->usage_monitoring = isset($request->usage_monitoring) ? $request->usage_monitoring : $tenant->usage_monitoring;
            $tenant->revenue_statements = isset($request->revenue_statements) ? $request->revenue_statements : $tenant->revenue_statements;
            $tenant->zone = isset($request->zone) ? $request->zone : $tenant->zone;
            $tenant->manage_zones = isset($request->manage_zones) ? $request->manage_zones : $tenant->manage_zones;
            $tenant->cms = isset($request->cms) ? $request->cms : $tenant->cms;
            $tenant->lost_found = isset($request->lost_found) ? $request->lost_found : $tenant->lost_found;
            $tenant->accounts = isset($request->accounts) ? $request->accounts : $tenant->accounts;
            $tenant->status = isset($request->status)
                ? CompanyInactiveService::normalizeStatus($request->status)
                : CompanyInactiveService::normalizeStatus($tenant->status ?? 'active');
            $tenant->password = (isset($request->password) && $request->password != NULL) ? Hash::make($request->password) : $tenant->password;
            // $this->syncTenantData($tenant);
            $tenant->save();

            if(isset($request->picture) && $request->picture != NULL && $tenant->picture && file_exists($tenant->picture)) {
                // unlink(public_path('pictures/'.$tenant->picture));
            }

            $filename = '';
            if(isset($request->picture) && $request->picture != NULL){
                $file = $request->file('picture');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('pictures'), $filename);
                $tenant->picture = 'pictures/'.$filename;

                $tenantData = is_array($tenant->data ?? null) ? $tenant->data : [];
                $tenantData['picture'] = $tenant->picture;
                $tenant->data = $tenantData;
            }
            // $this->syncTenantData($tenant);
            $tenant->save();

            $tenant->run(function () use ($tenant) {

                $system = "bidding";
                if($tenant->uber_plot_hybrid == "auto"){
                    $system = "auto_dispatch";
                }
                elseif($tenant->uber_plot_hybrid == "both"){
                    $system = "both";
                }
                else if($tenant->uber_plot_hybrid == "both"){
                    $system = "bidding";
                }

                $latestSetting = \DB::table('settings')
                    ->orderByDesc('id')
                    ->first();

                $data = [
                    'company_name' => $tenant->company_name,
                    'company_email' => $tenant->email,
                    'company_phone_no' => $tenant->phone,
                    'company_timezone' => $tenant->time_zone,
                    'company_currency' => $tenant->currency,
                    'company_admin_dispatch_sytem' => $system,
                    'map_settings' => $tenant->enable_smtp === "yes" ? 'default' : 'custom',
                    'stripe_payment' => $tenant->stripe_enable === "yes" ? "enable" : "disable",
                ];

                if ($latestSetting) {
                    // ✅ Update latest row
                    \DB::table('settings')
                        ->where('id', $latestSetting->id)
                        ->update($data);
                } else {
                    // ✅ No row exists → create new
                    \DB::table('settings')->insert($data);
                }
            });

            $newStatus = CompanyInactiveService::normalizeStatus($tenant->status ?? 'active');
            $socketNotify = null;

            if ($previousStatus === 'active' && $newStatus === 'inactive') {
                $socketNotify = CompanyInactiveService::handle($tenant->id, $previousStatus);
            }

            return response()->json([
                'success' => 1,
                'message' => "Client {$tenant->id} updated successfully!",
                'tenant' => $tenant,
                'newSubscriptionCreate' => $newSubscriptionCreate,
                'socket_notify' => $socketNotify,
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // private function syncTenantData(Tenant $tenant): void
    // {
    //     $data = is_array($tenant->data ?? null) ? $tenant->data : [];

    //     foreach ($this->tenantFrontendFields() as $field) {
    //         $value = $tenant->getAttribute($field);
    //         if ($value !== null) {
    //             $data[$field] = $value;
    //         }
    //     }

    //     if ($tenant->getAttribute('password')) {
    //         $data['password'] = $tenant->getAttribute('password');
    //     }

    //     $tenant->data = $data;
    // }

    // private function hydrateTenantDataForFrontend(Tenant $tenant): void
    // {
    //     $data = is_array($tenant->data ?? null) ? $tenant->data : [];

    //     foreach ($this->tenantFrontendFields() as $field) {
    //         $value = $tenant->getAttribute($field);
    //         if ($value !== null) {
    //             $data[$field] = $value;
    //         }
    //     }

    //     $tenant->data = $data;
    // }

    // private function tenantFrontendFields(): array
    // {
    //     return [
    //         'company_name',
    //         'company_admin_name',
    //         'user_name',
    //         'company_id',
    //         'email',
    //         'contact_person',
    //         'phone',
    //         'address',
    //         'city',
    //         'currency',
    //         'maps_api',
    //         'google_api_key',
    //         'barikoi_api_key',
    //         'search_api',
    //         'log_map_search_result',
    //         'voip',
    //         'drivers_allowed',
    //         'sub_company',
    //         'passengers_allowed',
    //         'uber_plot_hybrid',
    //         'dispatchers_allowed',
    //         'subscription_type',
    //         'fleet_management',
    //         'sos_features',
    //         'notes',
    //         'stripe_enable',
    //         'stripe_enablement',
    //         'units',
    //         'country_of_use',
    //         'time_zone',
    //         'enable_smtp',
    //         'dispatcher',
    //         'map',
    //         'push_notification',
    //         'usage_monitoring',
    //         'revenue_statements',
    //         'zone',
    //         'manage_zones',
    //         'cms',
    //         'lost_found',
    //         'accounts',
    //         'status',
    //         'picture',
    //         'database',
    //         'payment_status',
    //         'payment_method',
    //         'payment_amount',
    //         'billing_mode',
    //         'expiry_date',
    //         'subscription_start_date',
    //         'stripe_subscription_id',
    //         'stripe_customer_id',
    //         'password',
    //     ];
    // }

    public function companyCards(){
        try{
            $totalCompanies = Tenant::count();
            $activeCompanies = Tenant::where('data->expiry_date', '>=', Carbon::now()->format('Y-m-d'))->count();
            $monthlyRevenue = Tenant::where('data->subscription_start_date', '>=', Carbon::now()->startOfMonth())
                ->get()
                ->sum(fn ($tenant) => (float) data_get($tenant->data, 'payment_amount', 0));

            return response()->json([
                'success' => 1,
                'message' => 'Data fetched successfully',
                'total_companies' => $totalCompanies,
                'active_companies' => $activeCompanies,
                'monthly_revenue' => $monthlyRevenue 
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => "Something went wrong"
            ], 500);
        }
    }

    public function companyList(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $tenants = Tenant::orderBy("created_at","DESC");
            if($request->filled('status') && $request->status != 'all'){
                $tenants->where(function ($query) use ($request) {
                    $query->where('data->status', $request->status);

                    if ($request->status === 'active') {
                        $query->orWhereNull('data->status');
                    }
                });
            }
            if(isset($request->search) && $request->search != NULL){
                $tenants->where(function($query) use ($request){
                    $query->where("data->company_name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("data->email", "LIKE" ,"%".$request->search."%")
                            ->orWhere("company_name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("email", "LIKE" ,"%".$request->search."%");
                });
            }
            if (!empty($request->upcoming_subscription)) {
                $tenants->whereBetween(
                    DB::raw("DATE(JSON_UNQUOTE(JSON_EXTRACT(data, '$.expiry_date')))"),
                    [
                        Carbon::today()->toDateString(),
                        Carbon::today()->addDays($request->upcoming_subscription)->toDateString()
                    ]
                );
            }
            if (!empty($request->expired_subscription) && $request->expired_subscription == 1) {
                $tenants->whereRaw(
                    "DATE(JSON_UNQUOTE(JSON_EXTRACT(data, '$.expiry_date'))) < ?",
                    [Carbon::today()->toDateString()]
                );
            }
            $data = $tenants->paginate($perPage);

            $data->getCollection()->transform(function ($tenant) {
                // $this->hydrateTenantDataForFrontend($tenant);

                $monthlyAmount = 0;
                try {
                    tenancy()->initialize($tenant);
                    $monthlyAmount = DB::table('wallet_transactions')
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year)
                        ->sum('amount');
                } catch (\Throwable $e) {
                    \Log::warning('Company list tenant revenue lookup failed', [
                        'tenant' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    tenancy()->end();
                }

                $tenant->monthly_revenue = $monthlyAmount ?? 0;

                return $tenant;
            });
            return response()->json([
                'success' => 1,
                'message' => 'List fetched successfully',
                'list' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getEditCompany(Request $request){
        try{
            $request->validate([
                'id' => 'required'
            ]);

            $company = Tenant::where("id", $request->id)->first();
            $company->maps_api = MapsApi::normalize($company->maps_api);
            $subscription = Subscription::where('id', $company->subscription_type)->first();
            return response()->json([
                'success' => 1,
                'company' => $company,
                'subscription' => $subscription
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function companyDetails(Request $request){
        try{
            $data['map_api']['map_api_name'] = 'Google Maps';
            $data['map_api']['map_api_status'] = 'Active';
            $data['map_api']['monthly_request'] = '850K';
            $data['map_api']['monthly_cost'] = '$420';
            $data['map_api']['last_used'] = '2024-12-10 14:30';

            $data['call_api']['call_api_name'] = 'Twillio';
            $data['call_api']['call_api_status'] = 'Active';
            $data['call_api']['monthly_minutes'] = '1250';
            $data['call_api']['monthly_cost'] = '$125';
            $data['call_api']['last_used'] = '2024-12-10 14:30';

            $data['payment_info']['payment_mode'] = 'Online';
            $data['payment_info']['payment_status'] = 'PAID';
            $data['payment_info']['last_payment'] = '2024-12-01';
            $data['payment_info']['next_payment'] = '2025-01-01';
            $data['payment_info']['amount'] = '$199';
            
            $data['usage_statistic']['total_booking'] = '1247';
            $data['usage_statistic']['active_drivers'] = '42';
            $data['usage_statistic']['last_payment'] = '4.8';

            $data['payment_history'] = [
                [
                    'date' => '2024-12-01',
                    'amount' => '$199',
                    'status' => 'paid',
                    'method' => 'cash'
                ],
                [
                    'date' => '2024-12-01',
                    'amount' => '$199',
                    'status' => 'failed',
                    'method' => 'online'
                ],
                [
                    'date' => '2024-12-01',
                    'amount' => '$199',
                    'status' => 'processing',
                    'method' => 'cash'
                ],
                [
                    'date' => '2024-12-01',
                    'amount' => '$199',
                    'status' => 'pending',
                    'method' => 'cash'
                ]
            ];

            $data['api_configuration']['map_api_provide'] = 'Google Maps';
            $data['api_configuration']['call_api_provide'] = 'Twillio';
            $data['api_configuration']['payment_method'] = 'Online';
            $data['api_configuration']['plan_type'] = 'Premium';
            $data['api_configuration']['map_search_api_provider'] = 'Google Maps';

            return response()->json([
                'success' => 1,
                'data' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function cashPayment(Request $request){
        try{
            $request->validate([
                'id' => 'required'
            ]);

            $user = Tenant::where("id", $request->id)->first();
            $user->payment_status = "success";
            $user->payment_method = "cash";
            // $this->syncTenantData($user);
            $user->save();

            $subscription = Subscription::where("id", $user->subscription_type)->first();
            if($subscription->billing_cycle == "monthly"){
                $user->expiry_date = date('Y-m-d', strtotime('+1 month'));
            }
            elseif($subscription->billing_cycle == "quarterly"){
                $user->expiry_date = date('Y-m-d', strtotime('+3 months'));
            }
            elseif($subscription->billing_cycle == "yearly"){
                $user->expiry_date = date('Y-m-d', strtotime('+1 year'));
            }
            $user->subscription_start_date = date('Y-m-d');
            $user->payment_amount = $subscription->amount;
            // $this->syncTenantData($user);
            $user->save();

            $payment = new Transaction;
            $payment->user_id = $request->id;
            $payment->amount = $subscription->amount;
            $payment->status = 'paid';
            $payment->method = 'cash';
            $payment->save();

            $userSubscription = new UserSubscription;
            $userSubscription->subscription_id = $subscription->id;
            $userSubscription->user_id = $user->id;
            $userSubscription->plan_name = $subscription->plan_name;
            $userSubscription->billing_cycle = $subscription->billing_cycle;
            $userSubscription->amount = $subscription->amount;
            $userSubscription->features = $subscription->features;
            $userSubscription->status = 'active';
            if($subscription->billing_cycle == "monthly"){
                $userSubscription->expire_at = date('Y-m-d', strtotime('+1 month'));
            }
            elseif($subscription->billing_cycle == "quarterly"){
                $userSubscription->expire_at = date('Y-m-d', strtotime('+3 months'));
            }
            elseif($subscription->billing_cycle == "yearly"){
                $userSubscription->expire_at = date('Y-m-d', strtotime('+1 year'));
            }
            $userSubscription->save();

            return response()->json([
                'success' => 1,
                'message' => "Payment status updated successfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function subscriptionList(Request $request){
        try{
            $subscriptionList = Subscription::orderBy("id","DESC")->get();

            return response()->json([
                'success' => 1,
                'list' => $subscriptionList
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function createStripePaymentUrl(Request $request){
        try{
            $request->validate([
                'id' => 'required|string',
                'billing_mode' => 'nullable|in:one_time,auto_renew',
            ]);

            $stripeSecret = Setting::stripeSecret();
            if (!$stripeSecret) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Stripe secret key is not configured.',
                ], 500);
            }

            Stripe::setApiKey($stripeSecret);
            $YOUR_DOMAIN = env('FRONTEND_URL');
            
            $tenantId = $request->id;
            $tenant = Tenant::where("id", $tenantId)->first();
            if (!$tenant) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Company not found.',
                ], 404);
            }

            $subscription = Subscription::where("id", $tenant->subscription_type)->first();
            if (!$subscription) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Subscription plan not found.',
                ], 404);
            }

            $billingMode = $request->input('billing_mode', 'one_time');
            $amount = $subscription->amount * 100;
            $interval = "month";
            if($subscription->billing_cycle == "yearly"){
                $interval = "year";
            }

            $sessionPayload = [
                'mode' => $billingMode === 'auto_renew' ? 'subscription' : 'payment',
                'payment_method_types' => ['card'],
                'line_items' => [
                    $this->stripeCheckoutLineItem($subscription, $amount, $interval, $billingMode),
                ],
                'success_url' => $YOUR_DOMAIN . 'subscription-success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $YOUR_DOMAIN . 'payment-failed',
                'metadata' => [
                    'user_id' => $tenantId,
                    'subscription_id' => $subscription->id,
                    'billing_mode' => $billingMode,
                ],
            ];

            if ($billingMode === 'auto_renew') {
                $sessionPayload['subscription_data'] = [
                    'metadata' => [
                        'user_id' => $tenantId,
                        'subscription_id' => $subscription->id,
                        'billing_mode' => $billingMode,
                    ],
                ];
            }

            $checkout_session = Session::create($sessionPayload);

            return response()->json([
                'url' => $checkout_session->url,
                'billing_mode' => $billingMode,
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function stripeCheckoutLineItem(Subscription $subscription, int $amount, string $interval, string $billingMode): array
    {
        if ($billingMode === 'one_time') {
            return [
                'price_data' => [
                    'unit_amount' => $amount,
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => (string) $subscription->id,
                        'description' => $subscription->plan_name . ", ". $subscription->billing_cycle. ", ". $subscription->amount .", ". $subscription->features,
                    ],
                ],
                'quantity' => 1,
            ];
        }

        $products = Product::all(['limit' => 100]);
        $existing = collect($products->data)->firstWhere('name', $subscription->id);

        if($existing){
            $productId = $existing->id;
        }
        else{
            $product = Product::create([
                'name' => $subscription->id,
                'description' => $subscription->plan_name . ", ". $subscription->billing_cycle. ", ". $subscription->amount .", ". $subscription->features,
            ]);
            $productId = $product->id;
        }

        $existingPrice = Price::all([
            'limit' => 100,
            'product' => $productId,
        ]);

        $matching = collect($existingPrice->data)->firstWhere(fn($p) =>
            $p->unit_amount == $amount && ($p->recurring->interval ?? null) == $interval
        );

        if ($matching) {
            $priceId = $matching->id;
        } else {
            $price = Price::create([
                'unit_amount' => $amount,
                'currency' => 'usd',
                'recurring' => ['interval' => $interval],
                'product' => $productId,
            ]);
            $priceId = $price->id;
        }

        return [
            'price' => $priceId,
            'quantity' => 1,
        ];
    }

    private function subscriptionExpiryDate(?string $currentExpiry, string $billingCycle): string
    {
        $baseDate = Carbon::today();

        if ($currentExpiry) {
            try {
                $parsed = Carbon::parse($currentExpiry);
                if ($parsed->greaterThan($baseDate)) {
                    $baseDate = $parsed;
                }
            } catch (\Throwable) {
                $baseDate = Carbon::today();
            }
        }

        return match ($billingCycle) {
            'monthly' => $baseDate->copy()->addMonth()->toDateString(),
            'quarterly' => $baseDate->copy()->addMonths(3)->toDateString(),
            'yearly' => $baseDate->copy()->addYear()->toDateString(),
            default => $baseDate->copy()->addMonth()->toDateString(),
        };
    }

    public function confirmStripeSession(Request $request)
    {
        try {
            $request->validate([
                'session_id' => 'required|string',
            ]);

            $stripeSecret = Setting::stripeSecret();
            if (!$stripeSecret) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Stripe secret key is not configured.',
                ], 500);
            }

            Stripe::setApiKey($stripeSecret);

            $session = CheckoutSession::retrieve($request->session_id);

            if (($session->payment_status ?? null) !== 'paid' && empty($session->subscription)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Stripe session payment is not completed yet.',
                    'payment_status' => $session->payment_status ?? null,
                ], 422);
            }

            $result = $this->applyStripeSessionPayment($session);

            return response()->json([
                'success' => 1,
                'message' => 'Stripe payment confirmed successfully',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            \Log::warning('Stripe session confirmation failed', [
                'session_id' => $request->input('session_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function applyStripeSessionPayment($session): array
    {
        $metadata = $session->metadata ?? null;
        $subscriptionDetailsMetadata = $session->subscription_details->metadata ?? null;

        $userId = $metadata->user_id
            ?? $subscriptionDetailsMetadata->user_id
            ?? null;
        $subscriptionId = $metadata->subscription_id
            ?? $subscriptionDetailsMetadata->subscription_id
            ?? null;
        $billingMode = $metadata->billing_mode
            ?? $subscriptionDetailsMetadata->billing_mode
            ?? (!empty($session->subscription) ? 'auto_renew' : 'one_time');

        if (!$userId || !$subscriptionId) {
            throw new \RuntimeException('Stripe session is missing tenant or subscription metadata.');
        }

        $tenant = Tenant::where("id", $userId)->first();
        $subscription = Subscription::where("id", $subscriptionId)->first();

        if (!$tenant || !$subscription) {
            throw new \RuntimeException('Tenant or subscription not found for Stripe session.');
        }

        $stripeSubscriptionId = $session->subscription;
        $stripeCustomerId = $session->customer;

        $tenant->payment_status = "success";
        $tenant->payment_method = "stripe";
        $tenant->billing_mode = $billingMode;
        $tenant->stripe_subscription_id = $billingMode === 'auto_renew' ? $stripeSubscriptionId : '';
        $tenant->stripe_customer_id = $stripeCustomerId;
        $tenant->payment_amount = $subscription->amount;
        $tenant->expiry_date = $this->subscriptionExpiryDate($tenant->expiry_date, $subscription->billing_cycle);

        $tenant->subscription_start_date = date('Y-m-d');
        // $this->syncTenantData($tenant);
        $tenant->save();

        $existingUserSubscription = UserSubscription::where('user_id', $tenant->id)
            ->where('subscription_id', $subscription->id)
            ->where('status', 'active')
            ->where('amount', $subscription->amount)
            ->whereDate('created_at', now()->toDateString())
            ->first();

        if (!$existingUserSubscription) {
            $userSubscription = new UserSubscription;
            $userSubscription->subscription_id = $subscription->id;
            $userSubscription->user_id = $tenant->id;
            $userSubscription->plan_name = $subscription->plan_name;
            $userSubscription->billing_cycle = $subscription->billing_cycle;
            $userSubscription->amount = $subscription->amount;
            $userSubscription->features = $subscription->features;
            $userSubscription->status = 'active';
            $userSubscription->expire_at = $tenant->expiry_date;

            $userSubscription->save();
        }

        $existingPayment = Transaction::where('user_id', $tenant->id)
            ->where('amount', $subscription->amount)
            ->where('status', 'paid')
            ->where('method', 'card')
            ->whereDate('created_at', now()->toDateString())
            ->first();

        if (!$existingPayment) {
            $payment = new Transaction;
            $payment->user_id = $tenant->id;
            $payment->amount = $subscription->amount;
            $payment->status = 'paid';
            $payment->method = 'card';
            $payment->save();
        }

        return [
            'tenant_id' => $tenant->id,
            'payment_status' => $tenant->payment_status,
            'payment_method' => $tenant->payment_method,
            'payment_amount' => $tenant->payment_amount,
            'billing_mode' => $tenant->billing_mode,
            'expiry_date' => $tenant->expiry_date,
        ];
    }

    public function stripeWebhook(Request $request){
        try{
            Stripe::setApiKey(Setting::stripeSecret());
            
            $payload = $request->getContent();
            $sig_header = $request->header('Stripe-Signature');
            $endpoint_secret = Setting::stripeWebhookSecret();

            \Log::info("enter to webhook");

            try {
                $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
            } catch (\Exception $e) {
                return response($e->getMessage(), 400);
            }

            switch ($event->type) {
                case 'checkout.session.completed':

                    $session = $event->data->object;
                    $userId = $session->metadata->user_id ?? null;
                    $subscriptionId = $session->metadata->subscription_id ?? null;
                    $billingMode = $session->metadata->billing_mode ?? (!empty($session->subscription) ? 'auto_renew' : 'one_time');
                    $stripeSubscriptionId = $session->subscription;
                    $stripeCustomerId = $session->customer;
                    
                    $tenant = Tenant::where("id", $userId)->first();
                    $subscription = Subscription::where("id", $subscriptionId)->first();

                    $tenant->payment_status = "success";
                    $tenant->payment_method = "stripe";
                    $tenant->billing_mode = $billingMode;
                    $tenant->stripe_subscription_id  = $billingMode === 'auto_renew' ? $stripeSubscriptionId : '';
                    $tenant->stripe_customer_id  = $stripeCustomerId;
                    $tenant->payment_amount = $subscription->amount;
                    // $this->syncTenantData($tenant);
                    $tenant->save();

                    if($subscription->billing_cycle == "monthly"){
                        $tenant->expiry_date = date('Y-m-d', strtotime('+1 month'));
                    }
                    elseif($subscription->billing_cycle == "quarterly"){
                        $tenant->expiry_date = date('Y-m-d', strtotime('+3 months'));
                    }
                    elseif($subscription->billing_cycle == "yearly"){
                        $tenant->expiry_date = date('Y-m-d', strtotime('+1 year'));
                    }
                    $tenant->subscription_start_date = date('Y-m-d');
                    // $this->syncTenantData($tenant);
                    $tenant->save();

                    $userSubscription = new UserSubscription;
                    $userSubscription->subscription_id = $subscription->id;
                    $userSubscription->user_id = $tenant->id;
                    $userSubscription->plan_name = $subscription->plan_name;
                    $userSubscription->billing_cycle = $subscription->billing_cycle;
                    $userSubscription->amount = $subscription->amount;
                    $userSubscription->features = $subscription->features;
                    $userSubscription->status = 'active';
                    if($subscription->billing_cycle == "monthly"){
                        $userSubscription->expire_at = date('Y-m-d', strtotime('+1 month'));
                    }
                    elseif($subscription->billing_cycle == "quarterly"){
                        $userSubscription->expire_at = date('Y-m-d', strtotime('+3 months'));
                    }
                    elseif($subscription->billing_cycle == "yearly"){
                        $userSubscription->expire_at = date('Y-m-d', strtotime('+1 year'));
                    }
                    $userSubscription->save();

                    $payment = new Transaction;
                    $payment->user_id = $userId;
                    $payment->amount = $subscription->amount;
                    $payment->status = 'paid';
                    $payment->method = 'card';
                    $payment->save();

                    $paymentMethodId = null;

                    // Case 1: payment_intent exists (one-time or first subscription)
                    if (!empty($session->payment_intent)) {
                        $paymentIntent = \Stripe\PaymentIntent::retrieve($session->payment_intent);
                        $paymentMethodId = $paymentIntent->payment_method;
                    }

                    // Case 2: subscription Checkout (payment method via invoice)
                    elseif (!empty($session->subscription)) {
                        $subscriptionObj = \Stripe\Subscription::retrieve($session->subscription);
                        $paymentMethodId = $subscriptionObj->default_payment_method;
                    }

                    // Attach & set default only if exists
                    if ($paymentMethodId) {
                        $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);

                        try {
                            $paymentMethod->attach([
                                'customer' => $session->customer,
                            ]);
                        } catch (\Stripe\Exception\InvalidRequestException $e) {
                            // Already attached → ignore
                        }

                        \Stripe\Customer::update($session->customer, [
                            'invoice_settings' => [
                                'default_payment_method' => $paymentMethodId,
                            ],
                        ]);
                    }
                    break;

                case 'invoice.payment_succeeded':
                    $session = $event->data->object;
                    $userId = $session->subscription_details->metadata->user_id ?? null;
                    $subscriptionId = $session->subscription_details->metadata->subscription_id ?? null;
                    $stripeSubscriptionId = $session->subscription;
                    $stripeCustomerId = $session->customer;

                    $tenant = Tenant::where("id", $userId)->first();
                    $subscription = Subscription::where("id", $subscriptionId)->first();

                    $tenant->payment_status = "success";
                    $tenant->payment_method = "stripe";
                    $tenant->billing_mode = "auto_renew";
                    $tenant->stripe_subscription_id  = $stripeSubscriptionId;
                    $tenant->stripe_customer_id  = $stripeCustomerId;
                    $tenant->payment_amount = $subscription->amount;
                    // $this->syncTenantData($tenant);
                    $tenant->save();

                    if($subscription->billing_cycle == "monthly"){
                        $tenant->expiry_date = date('Y-m-d', strtotime('+1 month'));
                    }
                    elseif($subscription->billing_cycle == "quarterly"){
                        $tenant->expiry_date = date('Y-m-d', strtotime('+3 months'));
                    }
                    elseif($subscription->billing_cycle == "yearly"){
                        $tenant->expiry_date = date('Y-m-d', strtotime('+1 year'));
                    }
                    $tenant->subscription_start_date = date('Y-m-d');
                    // $this->syncTenantData($tenant);
                    $tenant->save();

                    $userSubscription = new UserSubscription;
                    $userSubscription->subscription_id = $subscription->id;
                    $userSubscription->user_id = $tenant->id;
                    $userSubscription->plan_name = $subscription->plan_name;
                    $userSubscription->billing_cycle = $subscription->billing_cycle;
                    $userSubscription->amount = $subscription->amount;
                    $userSubscription->features = $subscription->features;
                    $userSubscription->status = 'active';
                    if($subscription->billing_cycle == "monthly"){
                        $userSubscription->expire_at = date('Y-m-d', strtotime('+1 month'));
                    }
                    elseif($subscription->billing_cycle == "quarterly"){
                        $userSubscription->expire_at = date('Y-m-d', strtotime('+3 months'));
                    }
                    elseif($subscription->billing_cycle == "yearly"){
                        $userSubscription->expire_at = date('Y-m-d', strtotime('+1 year'));
                    }
                    $userSubscription->save();

                    $payment = new Transaction;
                    $payment->user_id = $userId;
                    $payment->amount = $subscription->amount;
                    $payment->status = 'paid';
                    $payment->method = 'card';
                    $payment->save();
                    break;

                // case 'invoice.payment_failed':
                //     $invoice = $event->data->object;
                //     $subscriptionId = $invoice->subscription;
                //     \Log::info($invoice);
                //     // Extend expiry date
                //     // \DB::table('subscriptions')->where('stripe_subscription_id', $subscriptionId)
                //     //     ->update([
                //     //         'status' => 'active',
                //     //         'expiry_date' => now()->addMonth(),
                //     //     ]);
                //     $userId = $session->subscription_details->metadata->user_id ?? null;
                //     $payment = new Transaction;
                //     $payment->user_id = $userId;
                //     $payment->amount = $subscription->amount;
                //     $payment->status = 'failed';
                //     $payment->method = 'card';
                //     $payment->save();
                //     break;

                // case 'payment_intent.payment_failed':
                //     $invoice = $event->data->object;
                //     $subscriptionId = $invoice->subscription;
                //     \Log::info($invoice);
                //     // Extend expiry date
                //     // \DB::table('subscriptions')->where('stripe_subscription_id', $subscriptionId)
                //     //     ->update([
                //     //         'status' => 'active',
                //     //         'expiry_date' => now()->addMonth(),
                //     //     ]);

                //     $userId = $session->subscription_details->metadata->user_id ?? null;
                //     $payment = new Transaction;
                //     $payment->user_id = $userId;
                //     $payment->amount = $subscription->amount;
                //     $payment->status = 'failed';
                //     $payment->method = 'card';
                //     $payment->save();
                //     break;
            }
            return response()->json([
                'success' => 1,
                'message' => 'Payment done and subscription created successfully'
            ]);
        }
        catch(\Exception $e){
            \Log::info($e->getMessage());
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500); 
        }
    }

    public function companyLogin(Request $request){
        try{
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);
            
            $tenant = TenantUser::where("data->email",$request->email)->first();
            
            if (!$tenant) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Tenant not found'
                ], 404);
            }

            if (!Hash::check($request->password, $tenant->data['password'])) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            if (CompanyInactiveService::isInactive($tenant->data['status'] ?? '')) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Your company has been deactivated. Please contact the administrator.',
                ], 403);
            }

            if($tenant->data['payment_status'] == "pending" || !isset($tenant->data['expiry_date']) || $tenant->data['expiry_date'] < Carbon::now()->format('Y-m-d')){
                return response()->json([
                    'error' => 1,
                    'message' => 'You have inactive subscription. Please contact to Admin'
                ]);
            }
            
            // $token = JWTAuth::fromUser($tenant);
            // $token = auth('tenant')->login($tenant);

            // $credentials = $request->only('email', 'password');
            // if (!$token = Auth::guard('tenant')->attempt($credentials)) {
            //     return response()->json(['error' => 'Unauthorized'], 401);
            // }
            // $tenant->device_token = $request->device_token;
            // $tenant->fcm_token = $request->fcm_token;
            $tenant->save();
            $token = auth('tenant')->login($tenant);

            $tenantData = $tenant->data;
            $timezone = $tenantData['time_zone'] ?? null;

            $centralTenant = Tenant::find($tenant->id);
            if ($centralTenant) {
                try {
                    $centralTenant->run(function () use (&$timezone) {
                        $settings = CompanySetting::orderBy('id', 'DESC')->first();
                        if ($settings?->company_timezone) {
                            $timezone = $settings->company_timezone;
                        }
                    });
                } catch (\Exception $e) {
                    \Log::warning('Could not load company timezone on login: ' . $e->getMessage());
                }
            }

            if ($timezone) {
                $tenantData['time_zone'] = $timezone;
                $tenantData['company_timezone'] = $timezone;
            }

            return response()->json([
                'message' => 'Tenant login successful',
                'token' => $token,
                'tenant_id' => $tenant->id,
                'tenant_data' => $tenantData,
            ]);
        }
        catch(\Exception $e){
            \Log::info($e->getMessage());
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500); 
        }
    }

    public function paymentHistory(Request $request){
        try{
            $request->validate([
                'user_id' => 'required'
            ]);
            
            $paymentHistory = Transaction::where('user_id', $request->user_id)->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'message' => 'Payment history fetched successfully',
                'list' => $paymentHistory
            ]);
        }
        catch(\Exception $e){
            \Log::info($e->getMessage());
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500); 
        }
    }

    public function deleteCompany(Request $request){
        try{
            $data = Tenant::where("id", $request->id)->first();

            if(isset($data) && $data != NULL){
                $data->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Company deleted successfully'
            ]);
        }
        catch(\Exception $e){
            \Log::info($e->getMessage());
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500); 
        }
    }

    public function dispatcherLogin(Request $request){
    try{
        $request->validate([
            'email' => 'required',
            'password' => 'required'
        ]);

        $user = Dispatcher::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json([
                'error' => 1,
                'message' => 'Dispatcher not found'
            ], 404);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if($user->status == "inactive"){
            return response()->json([
                'error' => 1,
                'message' => 'Your account is Inactive. Please contact your admin'
            ], 500);
        }
        
        $dispatcherTtlMinutes = 36 * 60; // 36 hours
        $tenantId = $request->header('database');
        $token = auth('dispatcher')
            ->setTTL($dispatcherTtlMinutes)
            ->login($user->withJwtTenantId($tenantId));

        $record = new CompanyDispatcherLog;
        $record->dispatcher_id = $user->id;
        $record->datetime = date("Y-m-d H:i:s");
        $record->type = "login";
        $record->save();

     $company = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

       return response()->json([
           'message' => 'Dispatcher login successful',
           'token' => $token,
           'expires_in' => $dispatcherTtlMinutes * 60,
           'user' => $user,
           'tenant_id' => $company?->id,
            'company_data' => $company,  
        ]);
    }
    catch(\Exception $e){
        return response()->json([
            'error' => 1,
            'message' => $e->getMessage()
        ]);
    }
   }

    public function dispatcherLogout()
    {
        $record = new CompanyDispatcherLog;
        $record->dispatcher_id = auth('dispatcher')->user()->id;
        $record->datetime = date("Y-m-d H:i:s");
        $record->type = "logout";
        $record->save();

        auth('dispatcher')->logout();
        return response()->json(['message' => 'Successfully logged out']);
    }

    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email'
            ]);

            $tenant = TenantUser::where('email', $request->email)
                ->orWhere("data->email", $request->email)
                ->first();

            if (!$tenant) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Tenant not found'
                ], 404);
            }

            $tenantEmail = $tenant->email ?? data_get($tenant->data, 'email');

            if (!$tenantEmail) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Email not found for tenant'
                ], 422);
            }

            $tenantModel = Tenant::find($tenant->id);
            if (!$tenantModel) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Tenant not found'
                ], 404);
            }

            $token = Password::broker('tenants')->createToken($tenantModel);
            
            $resetLink = env('CLIENT_FRONTEND_URL') .
                "/reset-password?token={$token}&email={$tenantEmail}";

            $mailer = \App\Services\MailConfigurationService::resolveMailer();

            Mail::mailer($mailer)->send('emails.reset-password', [
                'name' => $tenant->company_name ?? 'User',
                'resetLink' => $resetLink,
            ], function ($message) use ($tenantEmail) {
                $message->to($tenantEmail)
                    ->subject('Reset Your Password');
            });

            return response()->json([
                'success' => 1,
                'message' => 'Reset password link sent successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function resetPassword(Request $request){
        try{
            $request->validate([
                'email' => 'required|email',
                'token' => 'required',
                'password' => 'required|confirmed',
            ]);

            // $status = Password::broker('tenants')->reset(
            //     [
            //         'email' => $request->email,
            //         'password' => $request->password,
            //         'password_confirmation' => $request->password_confirmation,
            //         'token' => $request->token,
            //     ],
            //     function (TenantUser $user, string $password) {
            //         $data = $user->data;
            //         $data['password'] = Hash::make($password);

            //         $user->data = $data;
            //         $user->save();
            //     }
            // );

            // if ($status === Password::PASSWORD_RESET) {
            //     return response()->json([
            //         'error' => 0,
            //         'message' => 'Password reset successfully'
            //     ]);
            // }

            // return response()->json([
            //     'error' => 1,
            //     'message' => __($status)
            // ], 400);

            $tenant = TenantUser::where('email', $request->email)
                ->orWhere('data->email', $request->email)
                ->first();

            if (!$tenant) {
                return response()->json([
                    'error' => 1,
                    'message' => 'User not found'
                ], 404);
            }

            $tenantEmail = $tenant->email ?? data_get($tenant->data, 'email');
            if (!$tenantEmail) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Email not found for tenant'
                ], 422);
            }

            $resetTokenRecord = DB::table('password_reset_tokens')
                ->where('email', $tenantEmail)
                ->first();

            if (!$resetTokenRecord) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Invalid or expired reset token'
                ], 400);
            }

            if (!Hash::check($request->token, $resetTokenRecord->token)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Invalid reset token'
                ], 400);
            }

            $data = $tenant->data;
            $data['password'] = Hash::make($request->password);

            $tenant->data = $data;
            $tenant->save();

            $tenantRecord = Tenant::find($tenant->id);
            if ($tenantRecord) {
                $tenantRecord->password = Hash::make($request->password);
                if (!$tenantRecord->email && $tenantEmail) {
                    $tenantRecord->email = $tenantEmail;
                }
                $tenantRecord->save();
            }

            DB::table('password_reset_tokens')
            ->where('email', $tenantEmail)
            ->delete();

            return response()->json([
                'success' => 1,
                'message' => 'Password reset successfully'
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function sendNotification(){
        $userId = 1;
        event(new UserNotification(
            $userId,
            "Hello User {$userId}, you have a new notification!"
        ));
        return response()->json(['status' => 'sent']);
    }

    public function subscriptionUpdateWebhook(Request $request){
        try{
            Stripe::setApiKey(Setting::stripeSecret());
            
            $payload = $request->getContent();
            $sig_header = $request->header('Stripe-Signature');
            $endpoint_secret = Setting::stripeWebhookSecret();

            \Log::info("enter to webhook");

            try {
                $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
            } catch (\Exception $e) {
                return response($e->getMessage(), 400);
            }

            switch ($event->type) {
                case 'invoice.payment_succeeded':

                    $session = $event->data->object;
                    $userId = $session->metadata->user_id ?? null;
                    $subscriptionId = $session->metadata->subscription_id ?? null;
                    $stripeSubscriptionId = $session->subscription;
                    $stripeCustomerId = $session->customer;
                    
                    $tenant = Tenant::where("id", $userId)->first();
                    $subscription = Subscription::where("id", $subscriptionId)->first();

                    $tenant->payment_status = "success";
                    $tenant->payment_method = "stripe";
                    $tenant->billing_mode = "auto_renew";
                    $tenant->stripe_subscription_id  = $stripeSubscriptionId;
                    $tenant->stripe_customer_id  = $stripeCustomerId;
                    $tenant->payment_amount = $subscription->amount;
                    // $this->syncTenantData($tenant);
                    $tenant->save();

                    if($subscription->billing_cycle == "monthly"){
                        $tenant->expiry_date = date('Y-m-d', strtotime('+1 month'));
                    }
                    elseif($subscription->billing_cycle == "quarterly"){
                        $tenant->expiry_date = date('Y-m-d', strtotime('+3 months'));
                    }
                    elseif($subscription->billing_cycle == "yearly"){
                        $tenant->expiry_date = date('Y-m-d', strtotime('+1 year'));
                    }
                    $tenant->subscription_start_date = date('Y-m-d');
                    // $this->syncTenantData($tenant);
                    $tenant->save();

                    $userSubscription = new UserSubscription;
                    $userSubscription->subscription_id = $subscription->id;
                    $userSubscription->user_id = $tenant->id;
                    $userSubscription->plan_name = $subscription->plan_name;
                    $userSubscription->billing_cycle = $subscription->billing_cycle;
                    $userSubscription->amount = $subscription->amount;
                    $userSubscription->features = $subscription->features;
                    $userSubscription->status = 'active';
                    if($subscription->billing_cycle == "monthly"){
                        $userSubscription->expire_at = date('Y-m-d', strtotime('+1 month'));
                    }
                    elseif($subscription->billing_cycle == "quarterly"){
                        $userSubscription->expire_at = date('Y-m-d', strtotime('+3 months'));
                    }
                    elseif($subscription->billing_cycle == "yearly"){
                        $userSubscription->expire_at = date('Y-m-d', strtotime('+1 year'));
                    }
                    $userSubscription->save();

                    $payment = new Transaction;
                    $payment->user_id = $userId;
                    $payment->amount = $subscription->amount;
                    $payment->status = 'paid';
                    $payment->method = 'card';
                    $payment->save();

                    $paymentMethodId = null;

                    // Case 1: payment_intent exists (one-time or first subscription)
                    if (!empty($session->payment_intent)) {
                        $paymentIntent = \Stripe\PaymentIntent::retrieve($session->payment_intent);
                        $paymentMethodId = $paymentIntent->payment_method;
                    }

                    // Case 2: subscription Checkout (payment method via invoice)
                    elseif (!empty($session->subscription)) {
                        $subscriptionObj = \Stripe\Subscription::retrieve($session->subscription);
                        $paymentMethodId = $subscriptionObj->default_payment_method;
                    }

                    // Attach & set default only if exists
                    if ($paymentMethodId) {
                        $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);

                        try {
                            $paymentMethod->attach([
                                'customer' => $session->customer,
                            ]);
                        } catch (\Stripe\Exception\InvalidRequestException $e) {
                            // Already attached → ignore
                        }

                        \Stripe\Customer::update($session->customer, [
                            'invoice_settings' => [
                                'default_payment_method' => $paymentMethodId,
                            ],
                        ]);
                    }
                    break;
            }
        }
        catch(\Exception $e){
            \Log::info("Issue in subscription updation time");
            \Log::info($e->getMessage());
        }
    }
}
