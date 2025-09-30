<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant; 
use Illuminate\Support\Facades\Artisan;
use DB;
use Illuminate\Support\Facades\Hash;

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
                'user_name' => 'required|max:255',
                'company_id' => 'required|unique:tenants,data->company_id',
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
            ]);

            $count = Tenant::count();

            $tenantId = strtolower(str_replace(' ', '_', $request->company_name)).($count+1);

            $tenant = Tenant::create([
                'id' => $tenantId,
                'company_name' => $request->company_name,
                'company_admin_name' => $request->company_admin_name,
                'user_name' => $request->user_name,
                'email' => $request->email,
                'company_id' => $request->company_id,
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
                'user_name' => 'max:255',
                'contact_person' => 'max:255',
                'address' => 'max:255',
                'city' => 'max:255',
            ]);

            $tenant = Tenant::where("id", $request->id)->first();

            $tenant->company_name = isset($request->company_name) ? $request->company_name : $tenant->company_name;
            $tenant->company_admin_name = isset($request->company_admin_name) ? $request->company_admin_name : $tenant->company_admin_name;
            $tenant->user_name = isset($request->user_name) ? $request->user_name : $tenant->user_name;
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
}
