<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDriver;
use App\Models\CompanyContactUs;
use App\Models\CompanySetting;
use App\Models\WalletTransaction;
use App\Models\CompanyFAQ;
use App\Models\PackageSetting;

class SettingController extends Controller
{
    public function addWalletAmount(Request $request){
        try{
            $request->validate([
                'amount' => 'required',
                'comment' => 'required'
            ]);
            $userId = auth('driver')->user()->id;

            $user = CompanyDriver::where("id", $userId)->first();
            $user->wallet_balance += $request->amount;
            $user->save();
            
            $wallet = new WalletTransaction;
            $wallet->user_type = "driver";
            $wallet->user_id = $userId;
            $wallet->type = 'add';
            $wallet->amount = $request->amount;
            $wallet->comment = $request->comment;
            $wallet->save();

            return response()->json([
                'success' => 1,
                'message' => 'Amount added to wallet successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function balanceTransaction(Request $request){
        try{
            $userId = auth('driver')->user()->id;
            
            $transactionHistory = WalletTransaction::where("user_id", $userId)->where("user_type", "driver")->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'message' => 'Transaction history fetched successfully',
                'transactionHistory' => $transactionHistory
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function contactUs(Request $request){
        try{
            $request->validate([
                'message' => 'required'
            ]);

            $newRequest = new CompanyContactUs;
            $newRequest->user_type = "driver";
            $newRequest->user_id = auth('driver')->user()->id;
            $newRequest->message = $request->message;
            $newRequest->save();
            
            return response()->json([
                'success' => 1,
                'message' => 'Request submitted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function faqs(){
        try{
            $list = CompanyFAQ::orderBy("id","DESC")->get();
            
            return response()->json([
                'success' => 1,
                'message' => 'Faq list fetched successfully',
                'list' => $list
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function policies(){
        try{
            $settings = CompanySetting::orderBy("id", "DESC")
                        ->select("terms_conditions", "privacy_policy", "about_us")
                        ->first();
            
            return response()->json([
                'success' => 1,
                'data' => $settings
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
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

    public function getApiKeys(Request $request){
        try{
            $setting = CompanySetting::orderBy("id", "DESC")->first();

            $stripe_key = $setting->stripe_key;
            $stripe_secret_key = $setting->stripe_secret_key;
            $google_api_keys = $setting->google_api_keys;
            $barikoi_api_keys = $setting->barikoi_api_keys;
            $company_timezone = $setting->company_timezone;
            $company_currency = $setting->company_currency;

            $companyData = \DB::connection('central')->table('tenants')->where("id", $request->header('database'))->first();
            $data = \DB::connection('central')->table('settings')->orderBy("id", "DESC")->first();
            if(!isset($google_api_keys) || $google_api_keys == NULL){
                $google_map_key = $data->google_map_key;
            }
            if(!isset($barikoi_api_keys) || $barikoi_api_keys == NULL){
                $google_map_key = $data->barikoi_key;
            }
            if(!isset($company_timezone) || $company_timezone == NULL){
                $company_timezone = json_decode($tenant->data)->time_zone;
            }
            if(!isset($company_currency) || $company_currency == NULL){
                $company_currency = json_decode($tenant->data)->currency;
            }
            $enable_map = json_decode($tenant->data)->maps_api;

            $data = [
                'stripe_key' => $stripe_key,
                'stripe_secret_key' => $stripe_secret_key,
                'google_api_keys' => $google_api_keys,
                'barikoi_api_keys' => $barikoi_api_keys,
                'company_timezone' => $company_timezone,
                'company_currency' => $company_currency,
                'enable_map' => $enable_map,
            ];

            return response()->json([
                'success' => 1,
                'setting' => $data
            ], 200);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
