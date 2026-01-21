<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyContactUs;
use App\Models\CompanyFAQ;
use App\Models\CompanySetting;
use App\Models\CompanyRider;
use App\Models\CompanyChat;
use App\Models\WalletTransaction;
use App\Models\CompanyVehicleType;
use Illuminate\Support\Facades\Http;

class SettingController extends Controller
{
    public function createContactUs(Request $request){
        try{
            $request->validate([
                'message' => 'required'
            ]);

            $new = new CompanyContactUs;
            $new->user_type = 'user';
            $new->user_id = auth('rider')->user()->id;
            $new->message = $request->message;
            $new->save();

            return response()->json([
                'success' => 1,
                'message' => 'Your feedback has been sent successfully'
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

    public function addWalletAmount(Request $request){
        try{
            $request->validate([
                'amount' => 'required',
                'comment' => 'required'
            ]);
            $userId = auth('rider')->user()->id;

            $user = CompanyRider::where("id", $userId)->first();
            $user->wallet_balance += $request->amount;
            $user->save();
            
            $wallet = new WalletTransaction;
            $wallet->user_type = "user";
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
            $userId = auth('rider')->user()->id;
            
            $transactionHistory = WalletTransaction::where("user_id", $userId)->where("user_type", "user")->orderBy("id", "DESC")->get();

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

    public function getApiKeys(Request $request){
        try{
            $setting = CompanySetting::orderBy("id", "DESC")->first();

            $stripe_key = $setting->stripe_key;
            $stripe_secret_key = $setting->stripe_secret_key;
            $google_api_keys = $setting->google_api_keys;
            $barikoi_api_keys = $setting->barikoi_api_keys;
            $company_timezone = $setting->company_timezone;
            $company_currency = $setting->company_currency;
            $support_contact_no = $setting->support_contact_no;
            $support_emergency_no = $setting->support_emergency_no;
            $support_rescue_number = $setting->support_rescue_number;
            $company_booking_system = $setting->company_booking_system;

            $companyData = \DB::connection('central')->table('tenants')->where("id", $request->header('database'))->first();
            $data = \DB::connection('central')->table('settings')->orderBy("id", "DESC")->first();
            if(!isset($google_api_keys) || $google_api_keys == NULL){
                $google_map_key = $data->google_map_key;
            }
            if(!isset($barikoi_api_keys) || $barikoi_api_keys == NULL){
                $google_map_key = $data->barikoi_key;
            }
            if(!isset($company_timezone) || $company_timezone == NULL){
                $company_timezone = json_decode($companyData->data)->time_zone;
            }
            if(!isset($company_currency) || $company_currency == NULL){
                $company_currency = json_decode($companyData->data)->currency;
            }
            $enable_map = json_decode($companyData->data)->maps_api;
            $country_of_user = json_decode($companyData->data)->country_of_use;
            $company_booking_system = json_decode($companyData->data)->uber_plot_hybrid;

            if($company_booking_system == "auto"){
                $company_booking_system = "auto_dispatch";
            }
            else{
                $company_booking_system = "bidding";
            }

            $data = [
                'stripe_key' => $stripe_key,
                'stripe_secret_key' => $stripe_secret_key,
                'google_api_keys' => $google_api_keys,
                'barikoi_api_keys' => $barikoi_api_keys,
                'company_timezone' => $company_timezone,
                'company_currency' => $company_currency,
                'enable_map' => $enable_map,
                'support_contact_no' => $support_contact_no,
                'support_emergency_no' => $support_emergency_no,
                'support_rescue_number' => $support_rescue_number,
                'country_of_user' => $country_of_user,
                'company_booking_system' => $company_booking_system,
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

    public function vehicleList(Request $request){
        try{
            $vehicleList = CompanyVehicleType::orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'list' => $vehicleList
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

     public function sendMessage(Request $request){
        try{
            $chat = new CompanyChat;
            $chat->send_by = "user";
            $chat->user_id = auth("rider")->user()->id;
            $chat->driver_id = $request->driver_id;
            $chat->ride_id = $request->ride_id;
            $chat->message = $request->message;
            $chat->status = 'unread';
            $chat->save();

            $booking = CompanyBooking::where("id", $request->ride_id)->first();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/driver-message-notification', [
                'driver' => $request->driver_id,
                'booking' => [
                    'id' => $booking->id,
                    'booking_id' => $booking->booking_id,
                    'pickup_point' => $booking->pickup_point,
                    'destination_point' => $booking->destination_point,
                    'offered_amount' => $booking->offered_amount,
                    'distance' => $booking->distance,
                    'type' => 'auto_dispatch_plot'
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

    public function messageList(Request $request){
        try{
            $list = CompanyChat::where("user_id", auth("rider")->user()->id)->groupBy("ride_id")->with('rideDetail')->get();

            return response()->json([
                'success' => 1,
                'list' => $list
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function messageHistory(Request $request){
        try{
            $chat = CompanyChat::where("ride_id", $request->ride_id)->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'messages' => $chat
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
