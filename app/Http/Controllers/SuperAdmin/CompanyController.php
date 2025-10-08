<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant; 
use Illuminate\Support\Facades\Artisan;
use DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Subscription;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Price;
use Stripe\Product;
use Stripe\Webhook;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Subscription as StripeSubscription;
use App\Models\UserSubscription;

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
                // 'user_name' => 'required|max:255',
                // 'company_id' => 'required|unique:tenants,data->company_id',
                'contact_person' => 'required|max:255',
                'phone' => 'required',
                'address' => 'required|max:255',
                'city' => 'required|max:255',
                'currency' => 'required',
                'maps_api' => 'required',
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
                'stripe_enablement' => 'required',
                'units' => 'required',
                'country_of_use' => 'required',
                'time_zone' => 'required',
                'enable_smtp' => 'required',
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
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('pictures'), $filename);
            }
            $tenant = Tenant::create([
                'id' => $tenantId,
                'company_name' => $request->company_name,
                'company_admin_name' => $request->company_admin_name,
                // 'user_name' => $request->user_name,
                'email' => $request->email,
                // 'company_id' => $request->company_id,
                'contact_person' => $request->contact_person,
                'phone' => $request->phone,
                'address' => $request->address,
                'city' => $request->city,
                'currency' => $request->currency,
                'maps_api' => $request->maps_api,
                'search_api' => $request->search_api,
                'log_map_search_result' => $request->log_map_search_result,
                'voip' => $request->voip,
                'drivers_allowed' => $request->drivers_allowed,
                'sub_company' => $request->sub_company,
                'passengers_allowed' => $request->passengers_allowed,
                'uber_plot_hybrid' => $request->uber_plot_hybrid,
                'dispatchers_allowed' => $request->dispatchers_allowed,
                'subscription_type' => $request->subscription_type,
                'fleet_management' => $request->fleet_management,
                'sos_features' => $request->sos_features,
                'notes' => $request->notes,
                'stripe_enable' => $request->stripe_enable,
                'stripe_enablement' => $request->stripe_enablement,
                'units' => $request->units,
                'country_of_use' => $request->country_of_use,
                'time_zone' => $request->time_zone,
                'enable_smtp' => $request->enable_smtp,
                'dispatcher' => $request->dispatcher,
                'map' => $request->map,
                'push_notification' => $request->push_notification,
                'usage_monitoring' => $request->usage_monitoring,
                'revenue_statements' => $request->revenue_statements,
                'zone' => $request->zone,
                'manage_zones' => $request->manage_zones,
                'cms' => $request->cms,
                'lost_found' => $request->lost_found,
                'accounts' => $request->accounts,
                'status' => 'active',
                'password' => Hash::make($request->password),
                'picture' => (isset($filename) && $filename != '') ? public_path('pictures').'/'.$filename : '',
                'payment_status' => 'pending'
            ]);

            // $tenant->database()->manager()->createDatabase($tenant);
            // $tenant->createDatabase();

            $tenant->run(function () {
                \Artisan::call('migrate', [
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);
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
                'email' => 'email|unique:tenants,data->email',
                'password' => 'string|min:6',
                'company_admin_name' => 'max:255',
                // 'user_name' => 'max:255',
                'contact_person' => 'max:255',
                'address' => 'max:255',
                'city' => 'max:255',
            ]);

            $tenant = Tenant::where("id", $request->id)->first();

            $tenant->company_name = isset($request->company_name) ? $request->company_name : $tenant->company_name;
            $tenant->company_admin_name = isset($request->company_admin_name) ? $request->company_admin_name : $tenant->company_admin_name;
            // $tenant->user_name = isset($request->user_name) ? $request->user_name : $tenant->user_name;
            $tenant->email = isset($request->email) ? $request->email : $tenant->email;
            $tenant->contact_person = isset($request->contact_person) ? $request->contact_person : $tenant->contact_person;
            $tenant->phone = isset($request->phone) ? $request->phone : $tenant->phone;
            $tenant->address = isset($request->address) ? $request->address : $address->address;
            $tenant->city = isset($request->city) ? $request->city : $tenant->city;
            $tenant->currency = isset($request->currency) ? $request->currency : $tenant->currency;
            $tenant->maps_api = isset($request->maps_api) ? $request->maps_api : $tenant->maps_api;
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
            $tenant->status = isset($request->status) ? $request->status : $tenant->status;
            $tenant->password = Hash::make($request->password);
            $tenant->save();

            if(isset($request->picture) && $request->picture != NULL && $tenant->picture && file_exists($tenant->picture)) {
                unlink(public_path('pictures/'.$tenant->picture));
            }

            $$filename = '';
            if(isset($request->picture) && $request->picture != NULL){
                $file = $request->file('picture');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('pictures'), $filename);
                $tenant->picture = public_path('pictures').'/'.$filename;
            }
            $tenant->save();

            return response()->json([
                'success' => 1,
                'message' => "Client {$tenant->id} updated successfully!",
                'tenant' => $tenant
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => "Something went wrong"
            ], 500);
        }
    }

    public function companyCards(){
        try{
            $totalCompanies = Tenant::count();
            $activeCompanies = Tenant::where('data->status', 'active')->count();

            return response()->json([
                'success' => 1,
                'message' => 'Data fetched successfully',
                'total_companies' => $totalCompanies,
                'active_companies' => $activeCompanies
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
            $tenants = Tenant::orderBy("id","DESC")->paginate(10);
            if($request->status != 'all'){
                $tenants = Tenant::where('data->status', $request->status)->orderBy("id", "DESC")->paginate(10);
            }
            return response()->json([
                'success' => 1,
                'message' => 'List fetched successfully',
                'list' => $tenants
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => "Something went wrong"
            ], 500);
        }
    }

    public function getEditCompany(Request $request){
        try{
            $request->validate([
                'id' => 'required'
            ]);

            $company = Tenant::where("id", $request->id)->first();

            return response()->json([
                'success' => 1,
                'company' => $company
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
            $user->save();

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
            Stripe::setApiKey(env('STRIPE_SECRET'));
            $YOUR_DOMAIN = env('FRONTEND_URL');
            
            $tenantId = $request->id;
            $tenant = Tenant::where("id", $tenantId)->first();

            $subscription = Subscription::where("id", $tenant->subscription_type)->first();
            $amount = $subscription->amount * 100;
            $interval = "month";
            if($subscription->billing_cycle == "monthly"){
                $interval = "month";
            }
            elseif($subscription->billing_cycle == "yearly"){
                $interval = "year";
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
                $p->unit_amount == $amount && $p->recurring->interval == $interval
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

            $checkout_session = Session::create([
                'mode' => 'subscription',
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price' => $priceId,
                    'quantity' => 1,
                ]],
                'success_url' => $YOUR_DOMAIN . 'subscription-success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $YOUR_DOMAIN . 'subscription-cancel',
                'metadata' => [
                    'user_id' => $tenantId,
                    'subscription_id' => $subscription->id,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'user_id' => $tenantId,
                        'subscription_id' => $subscription->id,
                    ],
                ],
            ]);

            return response()->json(['url' => $checkout_session->url]);

        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function stripeWebhook(Request $request){
        try{
            $payload = $request->getContent();
            $sig_header = $request->header('Stripe-Signature');
            $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

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
                    
                    $tenant = Tenant::where("id", $userId)->first();
                    $subscription = Subscription::where("id", $userId)->first();

                    $tenant->payment_status = "success";
                    $tenant->payment_method = "stripe";
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
                    $tenant->save();
                    break;

                case 'invoice.payment_succeeded':
                    $session = $event->data->object;
                    $userId = $session->metadata->user_id ?? null;
                    $subscriptionId = $session->metadata->subscription_id ?? null;
                    
                    $tenant = Tenant::where("id", $userId)->first();
                    $subscription = Subscription::where("id", $userId)->first();

                    $userSubscription = new UserSubscription;
                    $userSubscription->subscription_id = $subscription->id;
                    $userSubscription->user_id = $tenant->id;
                    $userSubscription->plan_name = $subscription->plan_name;
                    $userSubscription->billing_cycle = $subscription->billing_cycle;
                    $userSubscription->amount = $subscription->amount;
                    $userSubscription->features = $subscription->features;
                    $userSubscription->status = 'active';
                    if($subscription->billing_cycle == "monthly"){
                        $userSubscription->expiry_at = date('Y-m-d', strtotime('+1 month'));
                    }
                    elseif($subscription->billing_cycle == "quarterly"){
                        $userSubscription->expiry_at = date('Y-m-d', strtotime('+3 months'));
                    }
                    elseif($subscription->billing_cycle == "yearly"){
                        $userSubscription->expiry_at = date('Y-m-d', strtotime('+1 year'));
                    }
                    $userSubscription->save();
                    break;

                case 'invoice.payment_failed':
                    $invoice = $event->data->object;
                    $subscriptionId = $invoice->subscription;
                    \Log::info($invoice);
                    // Extend expiry date
                    \DB::table('subscriptions')->where('stripe_subscription_id', $subscriptionId)
                        ->update([
                            'status' => 'active',
                            'expiry_date' => now()->addMonth(),
                        ]);
                    break;

                case 'payment_intent.payment_failed':
                    $invoice = $event->data->object;
                    $subscriptionId = $invoice->subscription;
                    \Log::info($invoice);
                    // Extend expiry date
                    \DB::table('subscriptions')->where('stripe_subscription_id', $subscriptionId)
                        ->update([
                            'status' => 'active',
                            'expiry_date' => now()->addMonth(),
                        ]);
                    break;
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
}
