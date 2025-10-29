<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanySetting;
use App\Models\TenantUser;
use App\Models\Tenant;
use App\Models\MobileAppSetting;
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
}
