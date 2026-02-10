<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDriver;
use App\Models\CompanyContactUs;
use App\Models\CompanySetting;
use App\Models\WalletTransaction;
use App\Models\DriverPackage;
use App\Models\CompanyFAQ;
use App\Models\CompanyChat;
use App\Models\CompanyBooking;
use App\Models\CompanyUser;
use App\Models\CompanyNotification;
use App\Models\PackageSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Models\CompanyToken;
use App\Services\FCMService;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;

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

            $settingData = CompanySetting::orderBy("id", "DESC")->first();
            if($settingData->map_settings == "default"){
            
                $centralData = (new Setting)
                    ->setConnection('central')
                    ->orderBy("id", "DESC")
                    ->first();
                    
                $mail_server = $centralData->smtp_host;
                $mail_from = $centralData->smtp_from_address;
                $mail_user_name = $centralData->smtp_user_name;
                $mail_password = $centralData->smtp_password;
                $mail_port = 587;
            }
            else{
                $mail_server = $settingData->mail_server;
                $mail_from = $settingData->mail_from;
                $mail_user_name = $settingData->mail_user_name;
                $mail_password = $settingData->mail_password;
                $mail_port = $settingData->mail_port;
            }

            config([
                'mail.mailers.smtp.host' => $mail_server,
                'mail.mailers.smtp.port' => $mail_port,
                'mail.mailers.smtp.username' => $mail_user_name,
                'mail.mailers.smtp.password' => $mail_password,
                'mail.from.address' => $mail_from,
                'mail.from.name' => $mail_user_name,
            ]);

            Mail::send('emails.wallet-topup', [
                'name' => $user->name ?? 'User',
                'amount' => $request->amount,
            ], function ($message) use ($user) {
                $message->to($user->email)
                        ->subject('Wallet Topup');
            });
            
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
            elseif($company_booking_system == "both"){
                $company_booking_system = "both";
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

    public function sendMessage(Request $request){
        try{
            $chat = new CompanyChat;
            $chat->send_by = "driver";
            $chat->driver_id = auth("driver")->user()->id;
            $chat->user_id = $request->user_id;
            $chat->ride_id = $request->ride_id;
            $chat->message = $request->message;
            $chat->status = 'unread';
            $chat->save();

            $user = CompanyUser::where("id", $request->user_id)->first();
            $booking = CompanyBooking::where("id", $request->ride_id)->first();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/user-message-notification', [
                'userId' => $request->user_id,
                'chat' => $chat
            ]);

            $notification = new CompanyNotification;
            $notification->user_type = "rider";
            $notification->user_id = $request->user_id;
            $notification->title = 'Message Alert';
            $notification->message = 'New message arrived from your driver';
            $notification->save();

            $tokens = CompanyToken::where("user_id", $request->user_id)->where("user_type", "rider")->get();

            if(isset($tokens) && $tokens != NULL){
                foreach($tokens as $key => $token){
                    FCMService::sendToDevice(
                        $token->fcm_token,
                        'Message Alert',
                        'New message arrived from your driver',
                        []
                    );
                }
            }

            return response()->json([
                'success' => 1,
                'message' => 'Message sent successfully',
                'chat' => $chat,
                'user' => $user
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function messageList(Request $request)
    {
        try { 
            $list = CompanyChat::where('driver_id', auth('driver')->user()->id)
                ->whereIn('id', function ($q) {
                    $q->select(DB::raw('MAX(id)'))
                    ->from('chats')
                    ->groupBy('ride_id');
                })
                ->with(['rideDetail', 'userDetail', 'driverDetail'])
                ->orderBy('created_at', 'DESC')
                ->get();

            return response()->json([
                'success' => 1,
                'list' => $list
            ]);
        }
        catch (\Exception $e) {
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

    public function notificationList(){
        try{
            $list = CompanyNotification::where("user_type", 'driver')->where("user_id", auth("driver")->user()->id)->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'list' => $list,
                'message' => 'Notification list fetched successfully'
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function purchasePackage(Request $request)
    {
        try {
            $request->validate([
                'package_type' => 'required|string',
                'days' => 'nullable|integer|min:1',
                'commission_per' => 'nullable|numeric|min:0',
                'post_paid_amount' => 'nullable|numeric|min:0',
                'package_duration' => 'nullable|in:day,week,month',
            ]);

            $driverId = auth('driver')->user()->id;

            switch ($request->package_type) {

                case 'per_ride_commission_topup':

                    $package = DriverPackage::firstOrNew([
                        'driver_id' => $driverId,
                        'package_type' => 'per_ride_commission_topup',
                    ]);

                    $package->pending_rides = ($package->pending_rides ?? 0) + 1;
                    $package->save();
                    break;

                case 'per_ride_commission_potpaid':

                    DriverPackage::where('driver_id', $driverId)
                        ->where('package_type', 'per_ride_commission_potpaid')
                        ->where('expire_date', '>=', now())
                        ->update(['expire_date' => now()->subDay()]);

                    DriverPackage::create([
                        'driver_id' => $driverId,
                        'package_type' => 'per_ride_commission_potpaid',
                        'start_date' => now()->toDateString(),
                        'expire_date' => now()->addDays($request->days)->toDateString(),
                        'commission_per' => $request->commission_per,
                    ]);
                    break;

                case 'packages_postpaid':

                    DriverPackage::create([
                        'driver_id' => $driverId,
                        'package_type' => 'packages_postpaid',
                        'start_date' => now()->toDateString(),
                        'expire_date' => now()->addDays($request->days)->toDateString(),
                        'post_paid_amount' => $request->post_paid_amount,
                    ]);
                    break;

                case 'packages_topup':

                    $days = match ($request->package_duration) {
                        'week' => 7,
                        'month' => 30,
                        default => 1,
                    };

                    DriverPackage::create([
                        'driver_id' => $driverId,
                        'package_type' => 'packages_topup',
                        'start_date' => now()->toDateString(),
                        'expire_date' => now()->addDays($days)->toDateString(),
                        'package_top_up_id' => $request->package_top_up_id,
                        'package_top_up_name' => $request->package_top_up_name,
                        'package_top_up_amount' => $request->package_top_up_amount,
                    ]);
                    break;

                default:
                    return response()->json([
                        'error' => 1,
                        'message' => 'Invalid package type'
                    ], 422);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Package purchased successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

}
