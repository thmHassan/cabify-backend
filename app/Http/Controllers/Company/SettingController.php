<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanySetting;
use App\Models\TenantUser;
use App\Models\Tenant;
use App\Models\MobileAppSetting;
use App\Models\PackageSetting;
use Hash;

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
}
