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

    public function getApiKeys(){
        try{
            $setting = CompanySetting::orderBy("id", "DESC")->first();

            return response()->json([
                'success' => 1,
                'setting' => $setting
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
