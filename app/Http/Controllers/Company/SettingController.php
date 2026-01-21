<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanySetting;
use App\Models\TenantUser;
use App\Models\Tenant;
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

class SettingController extends Controller
{
    public function getCompanyProfile(Request $request){
        try{
            $settings = CompanySetting::orderBy("id", "DESC")->first();
            $data = $settings;
            
            if(!isset($settings) || $settings == NULL){
                $settings = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();
                
                $data['company_name'] = $settings->data['company_name'];
                $data['company_email'] = $settings->data['email'];
                $data['company_phone_no'] = $settings->data['phone'];
                $data['company_business_license'] = "";
                $data['company_business_address'] = $settings->data['address'];
                $data['company_timezone'] = $settings->data['time_zone'];
                $data['company_description'] = "";
                $data = (object) $data;
            }
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

    public function saveCompanyProfile(Request $request){
        try{
            $request->validate([
                'company_name' => 'required|max:255',
                'company_email' => 'required|max:255',
                'company_phone_no' => 'required|max:255',
                'company_business_license' => 'required|max:255',
                'company_business_address' => 'required|max:255',
                'company_timezone' => 'required|max:255',
                'support_contact_no' => 'required|max:255',
                'support_emergency_no' => 'required|max:255',
                'support_rescue_number' => 'required|max:255',
            ]);
            
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            if(!isset($settings) || $settings == NULL){
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
            $settings->save();
            
            return response()->json([
                'success' => 1,
                'message' => "Company profile updated successfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePassword(Request $request){
        try{
            $request->validate([
                'current_password' => 'required',
                'new_password' => 'required',
            ]);

            $settings = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();
            
            if(!Hash::check($request->current_password, $settings->data['password'])){
                return response()->json([
                    'error' => 1,
                    'message' => "Current password is mismatched"
                ]);
            }
            
            $data = (new Tenant)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();
            
            $data->password = Hash::make($request->new_password);
            $data->save();

            return response()->json([
                'success' => 1,
                'message' => 'Password updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateMobileSetting(Request $request){
        try{
            $request->validate([
                'keys' => 'required',
            ]);
            
            foreach($request->keys as $key => $value){
                $data = MobileAppSetting::where("key", $key)->first();
                if(!isset($data) || $data == NULL){
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
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getMobileSetting(Request $request){
        try{
            $settings = MobileAppSetting::get();

            return response()->json([
                'success' => 1,
                'setting' => $settings
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function saveMainCommission(Request $request){
        try{
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            if(!isset($settings) || $settings == NULL){
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
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function savePackageTopup(Request $request){
        try{
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
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function editPackageTopup(Request $request){
        try{
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
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getCommissionData(Request $request){
        try{
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            $data['package_type'] = $settings->package_type;
            $data['package_days'] = $settings->package_days;
            $data['package_amount'] = $settings->package_amount;
            $data['package_percentage'] = $settings->package_percentage;
            $data['cancellation_per_day'] = $settings->cancellation_per_day;
            $data['waiting_time_charge'] = $settings->waiting_time_charge;

            $packageTopups = PackageSetting::orderBy("id", "DESC")->get();
            
            return response()->json([
                'success' => 1,
                'data' => [
                    'main_commission' => (object) $data,
                    'packageTopups' => $packageTopups
                ]
                ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deletePackageTopup(Request $request){
        try{
            $packageTopup = PackageSetting::where("id", $request->id)->first();

            if(isset($packageTopup) && $packageTopup != NULL){
                $packageTopup->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Package popup deleted successfully'
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function planDetail(Request $request){
        try{
            $user = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            $subscriptionData = (new Subscription)
                ->setConnection('central')
                ->where("id",$user->data['subscription_type'])
                ->first();

            return response()->json([
                'success' => 1,
                'planDetail' => $subscriptionData
            ]);
        }   
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function paymentHistory(Request $request){
        try{
            $transactionHistory = (new UserSubscription)
                ->setConnection('central')
                ->where("user_id",$request->header('database'))
                ->orderBy("id", "DESC")
                ->get();

            return response()->json([
                'success' => 1,
                'history' => $transactionHistory
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function stripeInformation(Request $request){
        try{
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            return response()->json([
                'success' => 1,
                'settings' => $settings
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function saveStripeInformation(Request $request){
        try{
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            if(!isset($settings) || $settings == NULL){
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
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function thirdPartyInformation(Request $request){
        try{
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            return response()->json([
                'success' => 1,
                'settings' => $settings
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function saveThirdPartyInformation(Request $request){
        try{
            $settings = CompanySetting::orderBy("id", "DESC")->first();

            if(!isset($settings) || $settings == NULL){
                $settings = new CompanySetting;
            }
            $settings->google_api_keys = $request->google_api_keys;
            $settings->barikoi_api_keys = $request->barikoi_api_keys;
            $settings->map_settings = $request->map_settings;
            $settings->mail_server = $request->mail_server;
            $settings->mail_from = $request->mail_from;
            $settings->mail_user_name = $request->mail_user_name;
            $settings->mail_password = $request->mail_password;
            $settings->mail_port = $request->mail_port;
            $settings->save();

            return response()->json([
                'success' => 1,
                'message' => 'Third party information saved successfully'
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function sendNotification(Request $request){
        try{
            if($request->user_type == "users"){
                $users = CompanyUser::whereNotNUll("device_token")->get();
            }
            else{
                $query = CompanyDriver::whereNotNUll("device_token");
                if($request->user_type == "pending_drivers"){
                        $query->where("status", "pending");
                }
                else if($request->user_type == "approved_drivers"){
                        $query->where("status", "accepted");
                }
                else if($request->user_type == "rejected_drivers"){
                        $query->where("status", "rejected");
                }   
                if($request->vehicle_id != NULL){
                    $query->where('vehicle_type', $request->vehicle_id);
                }
                $users = $query->get();
            }
            Artisan::call('app:send-notification', [
                'title' => $request->title,
                'body' => $request->body,
                'users' => $users,
            ]);
            return response()->json([
                'success' => 1,
                'message' => "Notification process started successfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function saveAppContent(Request $request){
        try{
            $setting = CompanySetting::orderBy("id", "DESC")->first();

            if(!isset($setting) || $setting == NULL){
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
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getAppContent(Request $request){
        try{
            $setting = CompanySetting::orderBy("id", "DESC")->first();

            return response()->json([
                'success' => 1,
                'data' => $setting
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getCompanyBookingSystem(){
        try{
            $setting = CompanySetting::orderBy("id", "DESC")->first();

            $company_booking_system = $setting->company_booking_system;

            return response()->json([
                'success' => 1,
                'company_booking_system' => $company_booking_system
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function updateCompanyBookingSystem(Request $request){
        try{
            $setting = CompanySetting::orderBy("id", "DESC")->first();
            $setting->company_booking_system = $request->company_booking_system;
            $setting->save();

            return response()->json([
                'success' => 1,
                'message' => "Booking system updated successfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function setDispatchSystem(Request $request){
        try{
            $settings = CompanyDispatchSystem::orderBy("id", "DESC")->get();

            foreach($settings as $key => $setting){
                if($setting->dispatch_system == "auto_dispatch_plot_base" && $setting->steps == "immediately_show_on_dispatcher_panel" && isset($request->auto_dispatch_plot_base['immediately_show_on_dispatcher_panel'])){
                    $setting->status = $request->auto_dispatch_plot_base['immediately_show_on_dispatcher_panel'];
                    $setting->priority = $request->auto_dispatch_plot_base['priority'];
                }
                elseif($setting->dispatch_system == "auto_dispatch_plot_base" && $setting->steps == "show_only_after_not_selected_in_auto_dispatch_first_try" && isset($request->auto_dispatch_plot_base['show_only_after_not_selected_in_auto_dispatch_first_try'])){
                    $setting->status = $request->auto_dispatch_plot_base['show_only_after_not_selected_in_auto_dispatch_first_try'];
                    $setting->priority = $request->auto_dispatch_plot_base['priority'];
                }
                elseif($setting->dispatch_system == "auto_dispatch_plot_base" && $setting->steps == "show_only_after_not_selected_in_auto_dispatch_second_try" && isset($request->auto_dispatch_plot_base['show_only_after_not_selected_in_auto_dispatch_second_try'])){
                    $setting->status = $request->auto_dispatch_plot_base['show_only_after_not_selected_in_auto_dispatch_second_try'];
                    $setting->priority = $request->auto_dispatch_plot_base['priority'];
                }
                elseif($setting->dispatch_system == "auto_dispatch_plot_base" && $setting->steps == "show_only_after_not_selected_in_auto_dispatch_third_try" && isset($request->auto_dispatch_plot_base['show_only_after_not_selected_in_auto_dispatch_third_try'])){
                    $setting->status = $request->auto_dispatch_plot_base['show_only_after_not_selected_in_auto_dispatch_third_try'];
                    $setting->priority = $request->auto_dispatch_plot_base['priority'];
                }
                elseif($setting->dispatch_system == "auto_dispatch_plot_base" && $setting->steps == "put_in_bidding_panel" && isset($request->auto_dispatch_plot_base['put_in_bidding_panel'])){
                    $setting->status = $request->auto_dispatch_plot_base['put_in_bidding_panel'];
                    $setting->priority = $request->auto_dispatch_plot_base['priority'];
                }
                elseif($setting->dispatch_system == "bidding_fixed_fare_plot_base" && $setting->steps == "wait_time_seconds" && isset($request->bidding_fixed_fare_plot_base['wait_time_seconds'])){
                    $setting->status = $request->bidding_fixed_fare_plot_base['wait_time_seconds'];
                    $setting->priority = $request->bidding_fixed_fare_plot_base['priority'];
                }
                elseif($setting->dispatch_system == "bidding_fixed_fare_plot_base" && $setting->steps == "immediately_show_on_dispatcher_panel" && isset($request->bidding_fixed_fare_plot_base['immediately_show_on_dispatcher_panel'])){
                    $setting->status = $request->bidding_fixed_fare_plot_base['immediately_show_on_dispatcher_panel'];
                    $setting->priority = $request->bidding_fixed_fare_plot_base['priority'];
                }
                elseif($setting->dispatch_system == "bidding_fixed_fare_plot_base" && $setting->steps == "shows_up_after_first_rejection_or_wait_time_elapsed" && isset($request->bidding_fixed_fare_plot_base['shows_up_after_first_rejection_or_wait_time_elapsed'])){
                    $setting->status = $request->bidding_fixed_fare_plot_base['shows_up_after_first_rejection_or_wait_time_elapsed'];
                    $setting->priority = $request->bidding_fixed_fare_plot_base['priority'];
                }
                elseif($setting->dispatch_system == "auto_dispatch_nearest_driver" && $setting->steps == "immediately_show_on_dispatcher_panel" && isset($request->auto_dispatch_nearest_driver['immediately_show_on_dispatcher_panel'])){
                    $setting->status = $request->auto_dispatch_nearest_driver['immediately_show_on_dispatcher_panel'];
                    $setting->priority = $request->auto_dispatch_nearest_driver['priority'];
                }
                elseif($setting->dispatch_system == "auto_dispatch_nearest_driver" && $setting->steps == "show_only_after_not_selected_in_auto_dispatch_first_try" && isset($request->auto_dispatch_nearest_driver['show_only_after_not_selected_in_auto_dispatch_first_try'])){
                    $setting->status = $request->auto_dispatch_nearest_driver['show_only_after_not_selected_in_auto_dispatch_first_try'];
                    $setting->priority = $request->auto_dispatch_nearest_driver['priority'];
                }
                elseif($setting->dispatch_system == "auto_dispatch_nearest_driver" && $setting->steps == "show_only_after_not_selected_in_auto_dispatch_second_try" && isset($request->auto_dispatch_nearest_driver['show_only_after_not_selected_in_auto_dispatch_second_try'])){
                    $setting->status = $request->auto_dispatch_nearest_driver['show_only_after_not_selected_in_auto_dispatch_second_try'];
                    $setting->priority = $request->auto_dispatch_nearest_driver['priority'];
                }
                elseif($setting->dispatch_system == "auto_dispatch_nearest_driver" && $setting->steps == "show_only_after_not_selected_in_auto_dispatch_third_try" && isset($request->auto_dispatch_nearest_driver['show_only_after_not_selected_in_auto_dispatch_third_try'])){
                    $setting->status = $request->auto_dispatch_nearest_driver['show_only_after_not_selected_in_auto_dispatch_third_try'];
                    $setting->priority = $request->auto_dispatch_nearest_driver['priority'];
                }
                elseif($setting->dispatch_system == "auto_dispatch_nearest_driver" && $setting->steps == "put_in_bidding_panel" && isset($request->auto_dispatch_nearest_driver['put_in_bidding_panel'])){
                    $setting->status = $request->auto_dispatch_nearest_driver['put_in_bidding_panel'];
                    $setting->priority = $request->auto_dispatch_nearest_driver['priority'];
                }
                elseif($setting->dispatch_system == "manual_dispatch_only" && isset($request->manual_dispatch_only)){
                    $setting->status = $request->manual_dispatch_only['status'];
                    $setting->priority = $request->manual_dispatch_only['priority'];
                }
                elseif($setting->dispatch_system == "bidding" && $setting->steps == "immediately_show_on_dispatcher_panel" && isset($request->bidding['immediately_show_on_dispatcher_panel'])){
                    $setting->status = $request->bidding['immediately_show_on_dispatcher_panel'];
                    $setting->priority = $request->bidding['priority'];
                }
                elseif($setting->dispatch_system == "bidding" && $setting->steps == "if_not_received_bid_in_first_10_seconds" && isset($request->bidding['if_not_received_bid_in_first_10_seconds'])){
                    $setting->status = $request->bidding['if_not_received_bid_in_first_10_seconds'];
                    $setting->priority = $request->bidding['priority'];
                }
                elseif($setting->dispatch_system == "bidding" && $setting->steps == "show_only_after_not_selected_in_auto_dispatch_first_try" && isset($request->bidding['show_only_after_not_selected_in_auto_dispatch_first_try'])){
                    $setting->status = $request->bidding['show_only_after_not_selected_in_auto_dispatch_first_try'];
                    $setting->priority = $request->bidding['priority'];
                }
                elseif($setting->dispatch_system == "bidding" && $setting->steps == "show_only_after_not_selected_in_auto_dispatch_second_try" && isset($request->bidding['show_only_after_not_selected_in_auto_dispatch_second_try'])){
                    $setting->status = $request->bidding['show_only_after_not_selected_in_auto_dispatch_second_try'];
                    $setting->priority = $request->bidding['priority'];
                }
                elseif($setting->dispatch_system == "bidding" && $setting->steps == "show_only_after_not_selected_in_auto_dispatch_third_try" && isset($request->bidding['show_only_after_not_selected_in_auto_dispatch_third_try'])){
                    $setting->status = $request->bidding['show_only_after_not_selected_in_auto_dispatch_third_try'];
                    $setting->priority = $request->bidding['priority'];
                }
                elseif($setting->dispatch_system == "bidding_fixed_fare_nearest_driver" && $setting->steps == "wait_time_seconds" && isset($request->bidding_fixed_fare_nearest_driver['wait_time_seconds'])){
                    $setting->status = $request->bidding_fixed_fare_nearest_driver['wait_time_seconds'];
                    $setting->priority = $request->bidding_fixed_fare_nearest_driver['priority'];
                }
                elseif($setting->dispatch_system == "bidding_fixed_fare_nearest_driver" && $setting->steps == "immediately_show_on_dispatcher_panel" && isset($request->bidding_fixed_fare_nearest_driver['immediately_show_on_dispatcher_panel'])){
                    $setting->status = $request->bidding_fixed_fare_nearest_driver['immediately_show_on_dispatcher_panel'];
                    $setting->priority = $request->bidding_fixed_fare_nearest_driver['priority'];
                }
                elseif($setting->dispatch_system == "bidding_fixed_fare_nearest_driver" && $setting->steps == "shows_up_after_first_rejection_or_wait_time_elapsed" && isset($request->bidding_fixed_fare_nearest_driver['shows_up_after_first_rejection_or_wait_time_elapsed'])){
                    $setting->status = $request->bidding_fixed_fare_nearest_driver['shows_up_after_first_rejection_or_wait_time_elapsed'];
                    $setting->priority = $request->bidding_fixed_fare_nearest_driver['priority'];
                }
                $setting->save();
            }
            return response()->json([
                'success' => 1,
                'message' => 'Data saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getDispatchSystem(Request $request){
        try{
            $data = CompanyDispatchSystem::orderBy("id", "ASC")->get();

            return response()->json([
                'success' => 1,
                'data' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function matchPassword(Request $request){
        try{
            $data = \DB::connection('central')->table('tenants')->where("id", auth('tenant')->user()->id)->first();

            $password = json_decode($data->data)->password;
            
            if(!Hash::check($request->password, $password)){
                return response()->json([
                    'error' => 1,
                    'message' => 'Invalid Password'
                ]);
            }
            return response()->json([
                'success' => 1,
                'message' => 'Password match successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function dashboard(Request $request){
        try{
            $activeRides = CompanyBooking::where("booking_status", "ongoing")->count();
            $cancelRides = CompanyBooking::where("booking_status", "cancelled")->count();
            $waitingRides = CompanyBooking::where("booking_status", "pending")->count();

            $data = [
                "activeRides" => $activeRides,
                "cancelRides" => $cancelRides,
                "waitingRides" => $waitingRides,
            ];

            return response()->json([
                'success' => 1,
                'data' => $data
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
