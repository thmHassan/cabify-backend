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
use App\Models\TenantUser;
use App\Models\CompanyNotification;
use App\Models\PackageSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Models\CompanyToken;
use App\Services\FCMService;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;
use App\Models\MobileAppSetting;
use App\Models\PackageRideCountSetting;
use App\Models\CompanyPlot;
use App\Models\CompanyDispatchSystem;
use Illuminate\Support\Facades\Schema;

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
            // if(isset($user->email) && $user->email != NULL){
            //     if($settingData->map_settings == "default"){
                
            //         $centralData = (new Setting)
            //             ->setConnection('central')
            //             ->orderBy("id", "DESC")
            //             ->first();
                        
            //         $mail_server = $centralData->smtp_host;
            //         $mail_from = $centralData->smtp_from_address;
            //         $mail_user_name = $centralData->smtp_user_name;
            //         $mail_password = $centralData->smtp_password;
            //         $mail_port = 587;
            //     }
            //     else{
            //         $mail_server = $settingData->mail_server;
            //         $mail_from = $settingData->mail_from;
            //         $mail_user_name = $settingData->mail_user_name;
            //         $mail_password = $settingData->mail_password;
            //         $mail_port = $settingData->mail_port;
            //     }
    
            //     config([
            //         'mail.mailers.smtp.host' => $mail_server,
            //         'mail.mailers.smtp.port' => $mail_port,
            //         'mail.mailers.smtp.username' => $mail_user_name,
            //         'mail.mailers.smtp.password' => $mail_password,
            //         'mail.from.address' => $mail_from,
            //         'mail.from.name' => $mail_user_name,
            //     ]);
    
            //     Mail::send('emails.wallet-topup', [
            //         'name' => $user->name ?? 'User',
            //         'amount' => $request->amount,
            //     ], function ($message) use ($user) {
            //         $message->to($user->email)
            //                 ->subject('Wallet Topup');
            //     });
            // }
            
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
            
            $transactionHistory = WalletTransaction::where("user_id", $userId)->where("user_type", "driver")->orderBy("id", "DESC")->where("comment", "!=", "Client Admin Amount Collected")->get();

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
            $newRequest->status = 'pending';
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
            $packageRideCount = PackageRideCountSetting::orderBy("id", "DESC")->get();
            
            return response()->json([
                'success' => 1,
                'data' => [
                    'main_commission' => (object) $data,
                    'packageTopups' => $packageTopups,
                    'packageRideCount' => $packageRideCount
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
            $tenantData = json_decode($companyData->data ?? '{}');
            $data = \DB::connection('central')->table('settings')->orderBy("id", "DESC")->first();
            if(!isset($barikoi_api_keys) || $barikoi_api_keys == NULL){
                $barikoi_api_keys = $data->barikoi_key;
            }
            if(!isset($company_timezone) || $company_timezone == NULL){
                $company_timezone = $tenantData->time_zone ?? null;
            }
            if(!isset($company_currency) || $company_currency == NULL){
                $company_currency = $tenantData->currency ?? null;
            }
            $enable_map = $tenantData->maps_api ?? null;
            $country_of_user = $tenantData->country_of_use ?? null;
            $units = $tenantData->units ?? null;
            $company_booking_system = $tenantData->uber_plot_hybrid ?? null;
            $tenant_google_api_key = $companyData->google_api_key ?? ($tenantData->google_api_key ?? null);
            $google_api_keys = in_array(strtolower((string) $enable_map), ['google', 'both'], true)
                ? (trim((string) ($google_api_keys ?? '')) ?: $tenant_google_api_key)
                : null;

            if($company_booking_system == "auto"){
                $company_booking_system = "auto_dispatch";
            }
            elseif($company_booking_system == "both"){
                $company_booking_system = $setting->company_booking_system;
            }
            else{
                $company_booking_system = "bidding";
            }

            $dispatchContext = $this->driverDispatchContext($company_booking_system);

            $data = [
                'stripe_key' => $stripe_key,
                'stripe_secret_key' => $stripe_secret_key,
                'google_api_keys' => $google_api_keys,
                'barikoi_api_keys' => $barikoi_api_keys,
                'company_timezone' => $company_timezone,
                'company_currency' => $company_currency,
                'enable_map' => $enable_map,
                'maps_api' => $enable_map,
                'search_api' => $tenantData->search_api ?? $enable_map,
                'map_style' => 'dark',
                'mapify_tiles_style_endpoint' => '/driver/mapify-tiles/dark',
                'mapify_tiles_endpoint' => '/driver/mapify-tiles/dark',
                'units' => $units,
                'distance_unit' => strtolower((string) $units) === 'miles' ? 'miles' : 'km',
                'support_contact_no' => $support_contact_no,
                'support_emergency_no' => $support_emergency_no,
                'support_rescue_number' => $support_rescue_number,
                'country_of_user' => $country_of_user,
                'company_booking_system' => $company_booking_system,
                'dispatch_system' => $dispatchContext['dispatch_system'],
                'supports_rank' => $dispatchContext['supports_rank'],
                'supports_bidding' => $dispatchContext['supports_bidding'],
                'fallback_to_bidding' => $dispatchContext['fallback_to_bidding'],
                'supports_manual_assignment' => $dispatchContext['supports_manual_assignment'],
                'show_rank' => $dispatchContext['show_rank'],
                'socket_url' => $this->clientSocketUrl(),
                'socket_port' => $this->clientSocketPort(),
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

    private function clientSocketUrl(): ?string
    {
        $url = config('services.node_socket.client_url') ?: config('services.node_socket.url');
        $url = preg_replace('#/socket-api/?$#', '', rtrim((string) $url, '/'));

        return $url !== '' ? $url : null;
    }

    private function clientSocketPort(): ?int
    {
        $port = config('services.node_socket.client_port');

        return is_numeric($port) && (int) $port > 0 ? (int) $port : null;
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
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/user-message-notification', [
                'userId' => $request->user_id,
                'database' => $request->header('database'),
                'chat' => $chat
            ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if(isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){

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
                'package_type' => 'nullable',
                'days' => 'required_without:package_type|integer|min:1',
                'post_paid_amount' => 'required_without:package_type|numeric|min:0',
                'package_duration' => 'required_without:package_type|in:day,week,month',
                'package_top_up_id' => 'required',
                'package_top_up_name' => 'required_without:package_type',
            ]);

            $driverId = auth('driver')->user()->id;

            $user = CompanyDriver::where("id", auth("driver")->user()->id)->first();

            if(isset($request->package_type) &&  $request->package_type == "ride_count_price"){
                $packageData = PackageRideCountSetting::where("id", $request->package_top_up_id)->first();
                
                if($user->wallet_balance < $packageData->package_amount){
                    return response()->json([
                        'error' => 1,
                        'message' => 'Your wallet balance is not sufficient' 
                    ], 400);
                }

                $user->ride_count_price = $packageData->package_ride_count;
                $user->wallet_balance -= $packageData->package_amount;
                $user->save();

                DriverPackage::create([
                    'driver_id' => $driverId,
                    'package_type' => 'ride_count_price',
                    'post_paid_amount' => $packageData->package_amount,
                    'package_top_up_id' => $request->package_top_up_id,
                ]);
            }
            else{
                if($user->wallet_balance < $request->post_paid_amount){
                    return response()->json([
                        'error' => 1,
                        'message' => 'Your wallet balance is not sufficient' 
                    ], 400);
                }
                $user->wallet_balance -= $request->post_paid_amount;
                $user->save();

                $add = $request->days;
                if($request->package_duration == "day"){
                    $add = $request->days;
                }
                elseif($request->package_duration == "week"){
                    $add = $request->days * 7;
                }
                elseif($request->package_duration == "month"){
                    $add = $request->days * 30;
                }
    
                DriverPackage::create([
                    'driver_id' => $driverId,
                    'package_type' => 'packages_postpaid',
                    'start_date' => now()->toDateString(),
                    'expire_date' => now()->addDays($add)->toDateString(),
                    'post_paid_amount' => $request->post_paid_amount,
                    'package_top_up_id' => $request->package_top_up_id,
                    'package_top_up_name' => $request->package_top_up_name,
                ]);
            }

            $wallet = new WalletTransaction;
            $wallet->user_type = "driver";
            $wallet->user_id = $driverId;
            $wallet->type = 'deduct';
            $wallet->amount = $request->package_type == "ride_count_price" ? $packageData->package_amount : $request->post_paid_amount;
            $wallet->comment = "Package purchase";
            $wallet->save();

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where('id', $request->header('database'))
                ->first();

            $pushEnabled = !isset($dataCheck) || ($dataCheck->data['push_notification'] ?? 'enable') === 'enable';

            if ($pushEnabled) {
                $packageLabel = $request->package_type === 'ride_count_price'
                    ? 'Ride count package'
                    : ($request->package_top_up_name ?? 'Package');

                FCMService::sendToDriver(
                    $driverId,
                    'Package Purchased',
                    "{$packageLabel} purchased successfully.",
                    [
                        'type' => 'package_purchase',
                        'package_type' => (string) ($request->package_type ?? 'packages_postpaid'),
                        'package_top_up_id' => (string) $request->package_top_up_id,
                    ]
                );
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

    public function getPurchasePackage(Request $request){
        try{
            $package = DriverPackage::where('driver_id', auth("driver")->user()->id)->orderBy("updated_at", "DESC")->whereDate('expire_date', '>=', now()->toDateString())->first();

            if((!isset($package) || $package == NULL) && auth("driver")->user()->ride_count_price > 0){

                $package = DriverPackage::where('driver_id', auth("driver")->user()->id)->where('package_type', 'ride_count_price')->orderBy("id", "DESC")->first();
                if ($package) {
                    $package->ride_count_price = auth("driver")->user()->ride_count_price;
                }
            }

            return response()->json([
                'success' => 1,
                'message' => "package information fetched successfully",
                'package' => $package
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getMobileSetting(Request $request)
    {
        try {
            $settings = MobileAppSetting::get();

            return response()->json([
                'success' => 1,
                'setting' => $settings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function plotList(Request $request)
    {
        try {
            $plots = CompanyPlot::orderBy("id", "DESC");
            if (isset($request->search) && $request->search != NULL) {
                $plots->where(function ($query) use ($request) {
                    $query->where("name", "LIKE", "%" . $request->search . "%")
                        ->orWhere("features", "LIKE", "%" . $request->search . "%");
                });
            }
            $data = $plots->get();
            return response()->json([
                'success' => 1,
                'list' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function driverRanking(Request $request)
    {
        try {
            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $setting = CompanySetting::orderBy("id", "DESC")->first();
            $dispatchContext = $this->driverDispatchContext($setting?->company_booking_system ?? "auto_dispatch");
            $rankSupported = (bool) ($dispatchContext["supports_rank"] ?? false);

            $plots = CompanyPlot::orderBy("id", "DESC")->get();
            $driver = $this->syncDriverPlotFromCoordinates($driver);

            $liveDrivers = CompanyDriver::query()
                ->whereNotNull("plot_id")
                ->where("online_status", "online")
                ->where("driving_status", "idle")
                ->where("updated_at", ">=", now()->subMinutes(15))
                ->orderByRaw("priority_plot IS NULL, CAST(priority_plot AS UNSIGNED) ASC")
                ->orderBy("id", "ASC")
                ->get();

            $driversByPlot = $liveDrivers->groupBy(fn ($item) => (string) $item->plot_id);
            $rank = null;
            $currentPlotDriverCount = 0;

            if ($rankSupported) {
                foreach ($plots as $plot) {
                    $plotDrivers = $driversByPlot->get((string) $plot->id, collect())->values();
                    $this->syncPlotQueue((int) $plot->id, $plotDrivers);

                    if ($driver && (string) $driver->plot_id === (string) $plot->id) {
                        $currentPlotDriverCount = $plotDrivers->count();
                        $position = $plotDrivers->search(fn ($item) => (string) $item->id === (string) $driver->id);
                        if ($position !== false) {
                            $rank = $position + 1;
                        }
                    }
                }
            }
            if ($rankSupported && $driver?->plot_id && $rank !== null && $currentPlotDriverCount < 1) {
                $currentPlotDriverCount = 1;
            }

            $plotRows = $plots->map(function ($plot) use ($driversByPlot, $driver, $currentPlotDriverCount) {
                $driverCount = $driversByPlot->get((string) $plot->id, collect())->count();
                $isCurrent = $driver && (string) $driver->plot_id === (string) $plot->id;
                if ($isCurrent && $currentPlotDriverCount > $driverCount) {
                    $driverCount = $currentPlotDriverCount;
                }
                return [
                    "id" => $plot->id,
                    "name" => $plot->name,
                    "plot_name" => $plot->name,
                    "driver_count" => $driverCount,
                    "is_current" => $isCurrent,
                ];
            })->values();

            $currentPlot = $driver?->plot_id
                ? CompanyPlot::where("id", $driver->plot_id)->first()
                : null;

            return response()->json([
                "success" => 1,
                "message" => "Driver dispatch context fetched successfully",
                "data" => [
                    ...$dispatchContext,
                    "rank_available" => $rankSupported && $rank !== null,
                    "current_plot_driver_count" => $currentPlotDriverCount,
                    "current_plot" => $currentPlot ? [
                        "id" => $currentPlot->id,
                        "name" => $currentPlot->name,
                        "driver_count" => $currentPlotDriverCount,
                    ] : null,
                    "current_driver" => [
                        "id" => $driver?->id,
                        "driver_id" => $driver?->id,
                        "name" => $driver?->name,
                        "profile_image" => $driver?->profile_image,
                        "rating" => $driver?->rating ?? "0",
                        "rank" => $rankSupported && $rank ? (int) $rank : null,
                        "plot_name" => $currentPlot?->name,
                        "is_current_driver" => true,
                    ],
                    "plots" => $plotRows,
                    "rankings" => [],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "error" => 1,
                "message" => $e->getMessage(),
            ], 500);
        }
    }

    private function driverDispatchContext(?string $companyBookingSystem = null): array
    {
        $enabledSystems = [];
        $fallbackToBidding = false;
        if (Schema::connection("tenant")->hasTable("dispatch_system")) {
            $enabledSystems = CompanyDispatchSystem::where("status", "enable")
                ->orderByRaw("priority IS NULL, priority ASC")
                ->orderBy("id", "ASC")
                ->pluck("dispatch_system")
                ->values()
                ->all();

            $fallbackToBidding = CompanyDispatchSystem::where("status", "enable")
                ->where("steps", "put_in_bidding_panel")
                ->when($enabledSystems[0] ?? null, function ($query, $dispatchSystem) {
                    $query->where("dispatch_system", $dispatchSystem);
                })
                ->exists();
        }

        $dispatchSystem = $enabledSystems[0] ?? null;
        if (!$dispatchSystem) {
            $dispatchSystem = $companyBookingSystem === "bidding"
                ? "bidding"
                : "auto_dispatch_plot_base";
        }

        $supportsBidding = in_array($dispatchSystem, [
            "bidding",
            "bidding_fixed_fare_plot_base",
            "bidding_fixed_fare_nearest_driver",
        ], true);

        if (!$supportsBidding && Schema::connection("tenant")->hasTable("dispatch_system")) {
            $supportsBidding = CompanyDispatchSystem::where("status", "enable")
                ->where(function ($query) {
                    $query->whereIn("dispatch_system", [
                        "bidding",
                        "bidding_fixed_fare_plot_base",
                        "bidding_fixed_fare_nearest_driver",
                    ])->orWhere(function ($query) {
                        $query->where("dispatch_system", "auto_dispatch_nearest_driver")
                            ->where("steps", "put_in_bidding_panel");
                    });
                })
                ->exists();
        }

        return [
            "dispatch_system" => $dispatchSystem,
            "company_booking_system" => $companyBookingSystem ?: "auto_dispatch",
            "supports_rank" => $dispatchSystem === "auto_dispatch_plot_base",
            "supports_bidding" => $supportsBidding,
            "fallback_to_bidding" => $fallbackToBidding,
            "supports_manual_assignment" => true,
            "show_rank" => $dispatchSystem === "auto_dispatch_plot_base",
        ];
    }

    private function syncDriverPlotFromCoordinates(?CompanyDriver $driver): ?CompanyDriver
    {
        if (!$driver || !$this->isValidCoordinate($driver->latitude, $driver->longitude)) {
            return $driver;
        }

        $plotId = $this->resolvePlotIdFromCoordinates((float) $driver->latitude, (float) $driver->longitude);
        if ((string) ($driver->plot_id ?? '') === (string) ($plotId ?? '')) {
            return $driver;
        }

        $driver->plot_id = $plotId;
        if (!$plotId) {
            $driver->priority_plot = null;
        } elseif (!$driver->priority_plot) {
            $driver->priority_plot = CompanyDriver::where("plot_id", $plotId)->max("priority_plot") + 1;
        }
        $driver->save();

        return $driver->fresh();
    }

    private function syncPlotQueue(int $plotId, $drivers): void
    {
        if (!Schema::connection("tenant")->hasTable("plot_driver_queues")) {
            return;
        }

        DB::table("plot_driver_queues")->where("plot_id", $plotId)->delete();

        $rows = [];
        foreach ($drivers->values() as $index => $driver) {
            $rows[] = [
                "plot_id" => $plotId,
                "driver_id" => $driver->id,
                "rank" => $index + 1,
                "created_at" => now(),
                "updated_at" => now(),
            ];
        }

        if ($rows) {
            DB::table("plot_driver_queues")->insert($rows);
        }
    }

    private function resolvePlotIdFromCoordinates(float $lat, float $lng): ?int
    {
        foreach (CompanyPlot::orderBy("id", "DESC")->get() as $plot) {
            $polygon = $this->plotPolygon($plot->features);
            if ($polygon && $this->pointInPolygon($lat, $lng, $polygon)) {
                return (int) $plot->id;
            }
        }

        return null;
    }

    private function plotPolygon($features): ?array
    {
        try {
            $feature = is_string($features) ? json_decode($features, true) : $features;
            if (isset($feature["features"][0]["geometry"])) {
                $feature = $feature["features"][0];
            }

            $geometry = $feature["geometry"] ?? $feature;
            $coordinates = $geometry["coordinates"] ?? null;
            $coordinates = is_string($coordinates) ? json_decode($coordinates, true) : $coordinates;

            if (!is_array($coordinates)) {
                return null;
            }

            return is_array($coordinates[0][0] ?? null) ? $coordinates[0] : $coordinates;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        if (count($polygon) === 2) {
            $lng1 = $polygon[0][0];
            $lat1 = $polygon[0][1];
            $lng2 = $polygon[1][0];
            $lat2 = $polygon[1][1];

            return $lat >= min($lat1, $lat2)
                && $lat <= max($lat1, $lat2)
                && $lng >= min($lng1, $lng2)
                && $lng <= max($lng1, $lng2);
        }

        $inside = false;
        $x = $lng;
        $y = $lat;
        $numPoints = count($polygon);

        for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private function isValidCoordinate($lat, $lng): bool
    {
        $latitude = is_numeric($lat) ? (float) $lat : null;
        $longitude = is_numeric($lng) ? (float) $lng : null;

        return $latitude !== null
            && $longitude !== null
            && $latitude >= -90
            && $latitude <= 90
            && $longitude >= -180
            && $longitude <= 180
            && !($latitude == 0.0 && $longitude == 0.0);
    }

    public function changeStatus(Request $request){
        try{
            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $driver->online_status = $request->status;
            $activeRideExists = CompanyBooking::where("driver", $driver->id)
                ->whereIn("booking_status", ["ongoing", "arrived", "started"])
                ->exists();
            $drivingStatus = $activeRideExists ? "busy" : "idle";
            $driver->driving_status = $drivingStatus;
            $driver->save();

            $tenantId = $request->header('database') ?: $request->header('x-database');
            $socketUrl = rtrim((string) config('services.node_socket.url'), '/');
            $socketSecret = (string) config('services.node_socket.internal_secret');

            if ($tenantId && $socketUrl && $socketSecret) {
                try {
                    Http::withHeaders([
                        'Authorization' => 'Bearer ' . $socketSecret,
                        'database' => $tenantId,
                    ])->timeout(5)->post($socketUrl . '/driver-status-change', [
                        'driver_id' => $driver->id,
                        'status' => $request->status,
                        'online_status' => $request->status,
                        'driving_status' => $drivingStatus,
                    ]);
                } catch (\Throwable $socketException) {
                    \Log::warning('Driver status socket sync failed', [
                        'driver_id' => $driver->id,
                        'tenant_id' => $tenantId,
                        'error' => $socketException->getMessage(),
                    ]);
                }
            }
            
            return response()->json([
                'success' => 1,
                'message' => "Status changed successfully"
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
