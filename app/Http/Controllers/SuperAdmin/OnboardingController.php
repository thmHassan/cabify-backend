<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\OnboardingRequest;
use App\Models\Tenant;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;

class OnboardingController extends Controller
{
    public function createOnboardingRequest(Request $request){
        try{
            $request->validate([
                'company_name' => 'max:255',
                'email' => 'email|unique:onboarding_requests,email',
                'password' => 'string|min:6',
                'company_admin_name' => 'max:255',
                'user_name' => 'max:255',
                'company_id' => 'unique:onboarding_requests,company_id',
                'contact_person' => 'max:255',
                'address' => 'max:255',
                'city' => 'max:255',
                'picture' => 'required|image|mimes:jpg,jpeg,png,gif|max:2048', 
            ]);

            $filename = '';
            if(isset($request->picture) && $request->picture != NULL){
                $file = $request->file('picture');
                $filename = 'pictures/'.time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('pictures'), $filename);
            }

            $onboardingRequest = OnboardingRequest::create([
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
                'status' => 'pending',
                'password' => Hash::make($request->password),
                'picture' => $filename
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Data saved successfully'
            ], 200);

        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ],500);
        }
    }

    public function editOnboardingRequest(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'company_name' => 'max:255',
                'email' => [
                    'email',
                    Rule::unique('onboarding_requests', 'email')->ignore($request->id, 'id'),
                ],
                'password' => 'string|min:6',
                'company_admin_name' => 'max:255',
                'user_name' => 'max:255',
                'company_id' => 'max:255',
                'contact_person' => 'max:255',
                'address' => 'max:255',
                'city' => 'max:255',
                'picture' => 'image|mimes:jpg,jpeg,png,gif|max:2048', 
            ]);

            $onboardingRequest = OnboardingRequest::where("id", $request->id)->first();
            $onboardingRequest->company_name = isset($request->company_name) ? $request->company_name : $onboardingRequest->company_name;
            $onboardingRequest->company_admin_name = isset($request->company_admin_name) ? $request->company_admin_name : $onboardingRequest->company_admin_name;
            $onboardingRequest->user_name = isset($request->user_name) ? $request->user_name : $onboardingRequest->user_name;
            $onboardingRequest->company_id = isset($request->company_id) ? $request->company_id : $onboardingRequest->company_id;
            $onboardingRequest->email = isset($request->email) ? $request->email : $onboardingRequest->email;
            $onboardingRequest->contact_person = isset($request->contact_person) ? $request->contact_person : $onboardingRequest->contact_person;
            $onboardingRequest->phone = isset($request->phone) ? $request->phone : $onboardingRequest->phone;
            $onboardingRequest->address = isset($request->address) ? $request->address : $address->address;
            $onboardingRequest->city = isset($request->city) ? $request->city : $onboardingRequest->city;
            $onboardingRequest->currency = isset($request->currency) ? $request->currency : $onboardingRequest->currency;
            $onboardingRequest->maps_api = isset($request->maps_api) ? $request->maps_api : $onboardingRequest->maps_api;
            $onboardingRequest->search_api = isset($request->search_api) ? $request->search_api : $onboardingRequest->search_api;
            $onboardingRequest->log_map_search_result = isset($request->log_map_search_result) ? $request->log_map_search_result : $onboardingRequest->log_map_search_result;
            $onboardingRequest->voip = isset($request->voip) ? $request->voip : $onboardingRequest->voip;
            $onboardingRequest->drivers_allowed = isset($request->drivers_allowed) ? $request->drivers_allowed : $onboardingRequest->drivers_allowed;
            $onboardingRequest->sub_company = isset($request->sub_company) ? $request->sub_company : $onboardingRequest->sub_company;
            $onboardingRequest->passengers_allowed = isset($request->passengers_allowed) ? $request->passengers_allowed : $onboardingRequest->passengers_allowed;
            $onboardingRequest->uber_plot_hybrid = isset($request->uber_plot_hybrid) ? $request->uber_plot_hybrid : $onboardingRequest->uber_plot_hybrid;
            $onboardingRequest->dispatchers_allowed = isset($request->dispatchers_allowed) ? $request->dispatchers_allowed : $onboardingRequest->dispatchers_allowed;
            $onboardingRequest->subscription_type = isset($request->subscription_type) ? $request->subscription_type : $onboardingRequest->subscription_type;
            $onboardingRequest->fleet_management = isset($request->fleet_management) ? $request->fleet_management : $onboardingRequest->fleet_management;
            $onboardingRequest->sos_features = isset($request->sos_features) ? $request->sos_features : $onboardingRequest->sos_features;
            $onboardingRequest->notes = isset($request->notes) ? $request->notes : $onboardingRequest->notes;
            $onboardingRequest->stripe_enable = isset($request->stripe_enable) ? $request->stripe_enable : $onboardingRequest->stripe_enable;
            $onboardingRequest->stripe_enablement = isset($request->stripe_enablement) ? $request->stripe_enablement : $onboardingRequest->stripe_enablement;
            $onboardingRequest->units = isset($request->units) ? $request->units : $onboardingRequest->units;
            $onboardingRequest->country_of_use = isset($request->country_of_use) ? $request->country_of_use : $onboardingRequest->country_of_use;
            $onboardingRequest->time_zone = isset($request->time_zone) ? $request->time_zone : $onboardingRequest->time_zone;
            $onboardingRequest->enable_smtp = isset($request->enable_smtp) ? $request->enable_smtp : $onboardingRequest->enable_smtp;
            $onboardingRequest->dispatcher = isset($request->dispatcher) ? $request->dispatcher : $onboardingRequest->dispatcher;
            $onboardingRequest->map = isset($request->map) ? $request->map : $onboardingRequest->map;
            $onboardingRequest->push_notification = isset($request->push_notification) ? $request->push_notification : $onboardingRequest->push_notification;
            $onboardingRequest->usage_monitoring = isset($request->usage_monitoring) ? $request->usage_monitoring : $onboardingRequest->usage_monitoring;
            $onboardingRequest->revenue_statements = isset($request->revenue_statements) ? $request->revenue_statements : $onboardingRequest->revenue_statements;
            $onboardingRequest->zone = isset($request->zone) ? $request->zone : $onboardingRequest->zone;
            $onboardingRequest->manage_zones = isset($request->manage_zones) ? $request->manage_zones : $onboardingRequest->manage_zones;
            $onboardingRequest->cms = isset($request->cms) ? $request->cms : $onboardingRequest->cms;
            $onboardingRequest->lost_found = isset($request->lost_found) ? $request->lost_found : $onboardingRequest->lost_found;
            $onboardingRequest->accounts = isset($request->accounts) ? $request->accounts : $onboardingRequest->accounts;
            $onboardingRequest->status = isset($request->status) ? $request->status : $onboardingRequest->status;
            $onboardingRequest->password = isset($request->password) ? Hash::make($request->password) : $onboardingRequest->password;
            
            if(isset($request->picture) && $request->picture != NULL && $tenant->picture && file_exists($tenant->picture)) {
                unlink(public_path('pictures/'.$tenant->picture));
            }

            $filename = '';
            if(isset($request->picture) && $request->picture != NULL){
                $file = $request->file('picture');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('pictures'), $filename);
                $onboardingRequest->picture = 'pictures/'.$filename;
            }
            
            $onboardingRequest->save();

            return response()->json([
                'success' => 1,
                'message' => 'Onboarding request updated successfully'
            ], 200);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function changeOnboardingRequestStatus(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'status' => 'required'
            ]);

            $onboardingRequest = OnboardingRequest::where("id", $request->id)->first();
            $onboardingRequest->status = $request->status;
            $onboardingRequest->save();

            if($request->status == "approved"){
                $count = Tenant::count();

                $tenantId = strtolower(str_replace(' ', '_', $onboardingRequest->company_name)).($count+1);

                $tenant = Tenant::create([
                    'id' => $tenantId,
                    'company_name' => $onboardingRequest->company_name,
                    'company_admin_name' => $onboardingRequest->company_admin_name,
                    'user_name' => $onboardingRequest->user_name,
                    'email' => $onboardingRequest->email,
                    'company_id' => $onboardingRequest->company_id,
                    'contact_person' => $onboardingRequest->contact_person,
                    'phone' => $onboardingRequest->phone,
                    'address' => $onboardingRequest->address,
                    'city' => $onboardingRequest->city,
                    'currency' => $onboardingRequest->currency,
                    'maps_api' => $onboardingRequest->maps_api,
                    'search_api' => $onboardingRequest->search_api,
                    'log_map_search_result' => $onboardingRequest->log_map_search_result,
                    'voip' => $onboardingRequest->voip,
                    'drivers_allowed' => $onboardingRequest->drivers_allowed,
                    'sub_company' => $onboardingRequest->sub_company,
                    'passengers_allowed' => $onboardingRequest->passengers_allowed,
                    'uber_plot_hybrid' => $onboardingRequest->uber_plot_hybrid,
                    'dispatchers_allowed' => $onboardingRequest->dispatchers_allowed,
                    'subscription_type' => $onboardingRequest->subscription_type,
                    'fleet_management' => $onboardingRequest->fleet_management,
                    'sos_features' => $onboardingRequest->sos_features,
                    'notes' => $onboardingRequest->notes,
                    'stripe_enable' => $onboardingRequest->stripe_enable,
                    'stripe_enablement' => $onboardingRequest->stripe_enablement,
                    'units' => $onboardingRequest->units,
                    'country_of_use' => $onboardingRequest->country_of_use,
                    'time_zone' => $onboardingRequest->time_zone,
                    'enable_smtp' => $onboardingRequest->enable_smtp,
                    'dispatcher' => $onboardingRequest->dispatcher,
                    'map' => $onboardingRequest->map,
                    'push_notification' => $onboardingRequest->push_notification,
                    'usage_monitoring' => $onboardingRequest->usage_monitoring,
                    'revenue_statements' => $onboardingRequest->revenue_statements,
                    'zone' => $onboardingRequest->zone,
                    'manage_zones' => $onboardingRequest->manage_zones,
                    'cms' => $onboardingRequest->cms,
                    'lost_found' => $onboardingRequest->lost_found,
                    'accounts' => $onboardingRequest->accounts,
                    'status' => 'active',
                    'password' => $onboardingRequest->password,
                    'picture' => $onboardingRequest->picture,
                ]);

                $tenant->run(function () {
                    \Artisan::call('migrate', [
                        '--path' => 'database/migrations/tenant',
                        '--force' => true,
                    ]);
                });
            }

            return response()->json([
                'success' => 1,
                'message' => 'Onboarding Request Status updated successfully'
            ], 200);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    public function onboardingRequestList(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $lists = OnboardingRequest::where("status", $request->status)->orderBy("id",'DESC');
            if(isset($request->search) && $request->search != NULL){
                $lists->where(function($query) use ($request){
                    $query->where("company_name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("email", "LIKE" ,"%".$request->search."%");
                });
            }
            
            $data = $lists->paginate($perPage);
            $pendingCount = OnboardingRequest::where("status", "pending")->count();
            $approvedCount = OnboardingRequest::where("status", "approved")->count();
            $rejectedCount = OnboardingRequest::where("status", "rejected")->count();
            return response()->json([
                'success' => 1,
                'message' => 'List fetched successfully',
                'list' => $data,
                'pendingCount' => $pendingCount,
                'approvedCount' => $approvedCount,
                'rejectedCount' => $rejectedCount
            ], 200);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    public function getSingleOnboardingRequest(Request $request){
        try{
            $onboardingRequest = OnboardingRequest::where("id", $request->id)->first();

            return response()->json([
                'success' => 1,
                'onboardingRequest' => $onboardingRequest
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }
}
