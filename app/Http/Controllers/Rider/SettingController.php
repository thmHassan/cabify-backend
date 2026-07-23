<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyContactUs;
use App\Models\CompanyFAQ;
use App\Models\CompanySetting;
use App\Models\CompanyRider;
use App\Models\CompanyDriver;
use App\Models\CompanyChat;
use App\Models\CompanyBooking;
use App\Models\CompanyNotification;
use App\Models\WalletTransaction;
use App\Models\CompanyVehicleType;
use App\Services\TenantMapProviderResolver;
use App\Services\DispatchContextService;
use App\Models\CompanyDispatchSystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Models\CompanyToken;
use App\Services\FCMService;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;
use App\Models\TenantUser;
use App\Models\MobileAppSetting;

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
            $new->status = 'pending';
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
            $stripePayment = $setting->stripe_payment ?? 'disable';
            $activeDispatchSystem = CompanyDispatchSystem::query()
                ->select('dispatch_system')
                ->where('status', 'enable')
                ->groupBy('dispatch_system')
                ->orderByRaw('MIN(priority) ASC')
                ->value('dispatch_system');

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
            $tenantMap = app(TenantMapProviderResolver::class)->resolve((string) $request->header('database'));
            $enable_map = $tenantMap['map_provider'];
            $country_of_user = $tenantData->country_of_use ?? null;
            $units = $tenantData->units ?? null;
            $tenantBookingSystem = $tenantData->uber_plot_hybrid ?? null;
            $tenant_google_api_key = $companyData->google_api_key ?? ($tenantData->google_api_key ?? null);
            $usesGoogleClient = in_array('google', [
                $tenantMap['map_provider'],
                $tenantMap['search_provider'],
                $tenantMap['geocoding_provider'],
            ], true);
            $google_api_keys = $usesGoogleClient
                ? ($tenantMap['credentials']['google']['browser_key']
                    ?? trim((string) ($google_api_keys ?? ''))
                    ?: $tenant_google_api_key)
                : null;

            $company_booking_system = $this->resolveCompanyBookingSystem(
                $tenantBookingSystem,
                $setting->company_booking_system,
                $activeDispatchSystem
            );

            if (!$activeDispatchSystem) {
                $activeDispatchSystem = $company_booking_system === "bidding"
                    ? "bidding"
                    : "auto_dispatch_plot_base";
            }

            $dispatchContext = app(DispatchContextService::class)->resolve($company_booking_system);
            $activeDispatchSystem = $dispatchContext['dispatch_system'];

            $data = [
                'stripe_key' => $stripe_key,
                'stripe_secret_key' => $stripe_secret_key,
                'google_api_keys' => $google_api_keys,
                'barikoi_api_keys' => $barikoi_api_keys,
                'company_timezone' => $company_timezone,
                'company_currency' => $company_currency,
                'enable_map' => $enable_map,
                'maps_api' => $enable_map,
                'search_api' => $tenantMap['search_provider'],
                'geocoding_api' => $tenantMap['geocoding_provider'],
                'routing_api' => $tenantMap['routing_provider'],
                'map_style' => 'dark',
                'mapify_tiles_style_endpoint' => '/rider/mapify-tiles/dark',
                'mapify_tiles_endpoint' => '/rider/mapify-tiles/dark',
                'mapify_search_endpoint' => '/rider/mapify-search',
                'mapify_geocoding_endpoint' => '/rider/mapify-geocoding',
                'mapify_reverse_geocoding_endpoint' => '/rider/mapify-reverse-geocoding',
                'support_contact_no' => $support_contact_no,
                'support_emergency_no' => $support_emergency_no,
                'support_rescue_number' => $support_rescue_number,
                'country_of_user' => $country_of_user,
                'units' => $units,
                'distance_unit' => strtolower((string) $units) === 'miles' ? 'miles' : 'km',
                'stripe_payment' => $stripePayment,
                'cash_payment' => 'enable',
                'support_tickets_enabled' => true,
                'company_booking_system' => $company_booking_system,
                'dispatch_context' => $dispatchContext,
                'customer_capabilities' => [
                    'dispatch_system' => $activeDispatchSystem,
                    'allow_asap' => $activeDispatchSystem !== 'manual_dispatch_only',
                    'allow_scheduled' => true,
                    'show_fare_now' => true,
                    'show_fare_scheduled' => false,
                    'distance_unit' => strtolower((string) $units) === 'miles' ? 'miles' : 'km',
                    'maps_api' => $enable_map,
                    'map_style' => 'dark',
                    'stripe_payment' => $stripePayment,
                    'cash_payment' => 'enable',
                    'support_tickets_enabled' => true,
                    'release_settings' => CompanySetting::resolveReleaseSettings($setting),
                ],
                'socket_url' => $this->clientSocketUrl(),
                'socket_port' => $this->clientSocketPort(),
            ];

            return response()->json([
                'success' => 1,
                'setting' => $data
            ], 200);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function vehicleList(Request $request){
        try{
            $vehicleList = CompanyVehicleType::orderBy("id", "DESC")->get()->map(function ($vehicle) {

                $formattedAttributes = [];

                if (!empty($vehicle->attributes)) {
                    foreach ($vehicle->attributes as $key => $value) {
                        $formattedAttributes[] = [
                            'name' => $key,
                            'allowed' => $value === 'yes' ? true : false,
                        ];
                    }
                }

                $vehicle->attributes = $formattedAttributes;

                return $vehicle;
            });

            return response()->json([
                'success' => 1,
                'list' => $vehicleList
            ]);
        }
        catch(\Exception $e){
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
            $chat->send_by = "user";
            $chat->user_id = auth("rider")->user()->id;
            $chat->driver_id = $request->driver_id;
            $chat->ride_id = $request->ride_id;
            $chat->message = $request->message;
            $chat->status = 'unread';
            $chat->save();

            $booking = CompanyBooking::where("id", $request->ride_id)->first();
            $driver = CompanyDriver::where("id", $request->driver_id)->first();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/driver-message-notification', [
                'driverId' => $request->driver_id,
                'database' => $request->header('database'),
                'chat' => $chat
            ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if(isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){
                $notification = new CompanyNotification;
                $notification->user_type = "driver";
                $notification->user_id = $request->driver_id;
                $notification->title = 'Message Alert';
                $notification->message = 'New message arrived from passenger';
                $notification->save();

                $tokens = CompanyToken::where("user_id", $request->driver_id)->where("user_type", "driver")->get();

                if(isset($tokens) && $tokens != NULL){
                    foreach($tokens as $key => $token){
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            'Message Alert',
                            'New message arrived from passenger',
                            []
                        );
                    }
                }
            }

            return response()->json([
                'success' => 1,
                'message' => 'Message sent successfully',
                'chat' => $chat,
                'driver' => $driver
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function messageList(Request $request)
    {
        try {
            $list = CompanyChat::where('user_id', auth('rider')->user()->id)
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
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function notificationList(){
        try{
            $list = CompanyNotification::where("user_type", 'rider')->where("user_id", auth("rider")->user()->id)->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'list' => $list,
                'message' => 'Notification list fetched successfully'
            ]);
        }
        catch(\Exception $e){
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

    private function resolveCompanyBookingSystem(
        mixed $tenantBookingSystem,
        mixed $storedBookingSystem,
        ?string $activeDispatchSystem
    ): string {
        $tenantBookingSystem = strtolower(trim((string) $tenantBookingSystem));
        $storedBookingSystem = strtolower(trim((string) $storedBookingSystem));

        if ($tenantBookingSystem === 'auto') {
            return 'auto_dispatch';
        }

        if ($tenantBookingSystem === 'bidding') {
            return 'bidding';
        }

        if ($tenantBookingSystem === 'both' && in_array($storedBookingSystem, ['auto_dispatch', 'bidding', 'both'], true)) {
            return $storedBookingSystem;
        }

        if (in_array($storedBookingSystem, ['auto_dispatch', 'bidding', 'both'], true)) {
            return $storedBookingSystem;
        }

        return str_starts_with((string) $activeDispatchSystem, 'bidding')
            ? 'bidding'
            : 'auto_dispatch';
    }
}
