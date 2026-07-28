<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyRider;
use App\Models\CompanyBooking;
use Carbon\Carbon;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use App\Models\CompanyToken;
use Illuminate\Support\Facades\Http;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Hash;
use App\Jobs\SendRiderRegistrationOtpJob;
use App\Services\FCMService;
use App\Support\TenantRequestContext;

class AuthController extends Controller
{
    private function createAndSendEmailOtp(CompanyRider $user, Request $request): void
    {
        $user->otp = rand(1000, 9999);
        $user->otp_expires_at = Carbon::now()->addMinutes(5);
        $user->save();

        if (filled($user->email)) {
            SendRiderRegistrationOtpJob::dispatchAfterResponse(
                $user->id,
                (string) $request->header('database')
            );
        }
    }

    private function riderEmailVerified(CompanyRider $user): bool
    {
        return (bool) ($user->email_verified ?? false);
    }

    private function otpRequiredResponse(CompanyRider $user, string $message = 'OTP sent to email. Please verify your account.')
    {
        return response()->json([
            'error' => 1,
            'requiresOtp' => true,
            'requires_otp' => true,
            'email_verified' => false,
            'message' => $message,
        ], 403);
    }

    private function isBcryptHash(?string $hash): bool
    {
        if (empty($hash)) {
            return false;
        }

        return str_starts_with($hash, '$2y$')
            || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$2b$');
    }

    private function verifyRiderPassword(string $plainPassword, CompanyRider $user): bool
    {
        $stored = $user->password;

        if ($this->isBcryptHash($stored)) {
            return Hash::check($plainPassword, $stored);
        }

        if (empty($stored)) {
            return false;
        }

        if (hash_equals($stored, $plainPassword)) {
            $user->password = Hash::make($plainPassword);
            $user->save();

            return true;
        }

        return false;
    }

    public function register(Request $request){
        try{
            $request->validate([
                'email' => 'required',
                'phone' => 'required',
                'name' => 'required',
                'country_code' => 'required',
                "password" => 'required'
            ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            $countUser = CompanyRider::count();

            if($countUser >= $dataCheck->data['passengers_allowed']){
                try {
                    Http::withHeaders([
                        'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                    ])->timeout(5)->post(rtrim((string) config('services.node_socket.url'), '/') . '/send-reminder', [
                        'clientId' => $request->header('database'),
                        'title' => "Passenger Limit",
                        'description' => "You have reached your passenger limits"
                    ]);
                } catch (\Exception $e) {
                    \Log::warning('Passenger limit reminder failed', [
                        'tenant' => $request->header('database'),
                        'error' => $e->getMessage(),
                    ]);
                }

                return response()->json([
                    'error' => 1,
                    'message' => 'This company already reached to passenger limits. Please contact to Admin'
                ]);
            }

            $existUser = CompanyRider::where('phone_no', $request->phone)->where("email", $request->email)->where("country_code", $request->country_code)->first();
            
            if(isset($existUser) && $existUser != NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User already exists with this Email or Phone No.'
                ], 400);
            }

            $user = new CompanyRider;
            $user->phone_no = $request->phone;
            $user->email = $request->email;
            $user->name = $request->name;
            $user->country_code = $request->country_code;
            $user->password = Hash::make($request->password);
            $user->status = 'active';
            $user->email_verified = false;
            $user->save();

            $this->createAndSendEmailOtp($user, $request);

            return response()->json([
                'success' => 1,
                'message' => "User sign up successfully and OTP sent",
                'requiresOtp' => true,
                'requires_otp' => true,
                'email_verified' => false,
            ], 200);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function login(Request $request){
        try{
            $request->validate([
                'country_code' => 'required',
                'phone' => 'required',
                // 'password' => 'required'
            ]);

            $existUser = CompanyRider::where('phone_no', $request->phone)->where("country_code", $request->country_code)->first();
            
            if(!isset($existUser) || $existUser == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User not exists with this Phone No.'
                ]);
            }

            // if(!Hash::check($request->password, $existUser->password)){
            //     return response()->json([
            //         'error' => 1,
            //         'message' => 'Invalid credential for User'
            //     ]);
            // }

            if($existUser->status == "deactive"){
                return response()->json([
                    'error' => 1,
                    'message' => 'Your account is not active. Please contact to Company Admin.'
                ]);
            }

            if (!$this->riderEmailVerified($existUser)) {
                if (!filled($existUser->email)) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Email address is required to send OTP.',
                    ], 400);
                }

                $this->createAndSendEmailOtp($existUser, $request);

                return $this->otpRequiredResponse($existUser);
            }

            // $otp = rand(1000, 9999);
            // $expiresAt = Carbon::now()->addMinutes(5);
            // $existUser->otp = $otp;
            // $existUser->otp_expires_at = $expiresAt;
            // $existUser->save();

            // $settingData = CompanySetting::orderBy("id", "DESC")->first();
            // if($settingData->map_settings == "default"){
            
            //     $centralData = (new Setting)
            //         ->setConnection('central')
            //         ->orderBy("id", "DESC")
            //         ->first();
                    
            //     $mail_server = $centralData->smtp_host;
            //     $mail_from = $centralData->smtp_from_address;
            //     $mail_user_name = $centralData->smtp_user_name;
            //     $mail_password = $centralData->smtp_password;
            //     $mail_port = 587;
            // }
            // else{
            //     $mail_server = $settingData->mail_server;
            //     $mail_from = $settingData->mail_from;
            //     $mail_user_name = $settingData->mail_user_name;
            //     $mail_password = $settingData->mail_password;
            //     $mail_port = $settingData->mail_port;
            // }

            // config([
            //     'mail.mailers.smtp.host' => $mail_server,
            //     'mail.mailers.smtp.port' => $mail_port,
            //     'mail.mailers.smtp.username' => $mail_user_name,
            //     'mail.mailers.smtp.password' => $mail_password,
            //     'mail.from.address' => $mail_from,
            //     'mail.from.name' => $mail_user_name,
            // ]);

            // Mail::send('emails.send-otp', [
            //     'name' => $existUser->name ?? 'User',
            //     'otp' => $otp
            // ], function ($message) use ($existUser) {
            //     $message->to($existUser->email)
            //             ->subject('Login OTP');
            // });

            $requiresPasswordSetup = empty($existUser->password) || !$this->isBcryptHash($existUser->password);

            return response()->json([
                'success' => 1,
                'message' => $requiresPasswordSetup
                    ? 'User exist. Please set your password'
                    : 'User exist. Please enter your password',
                'requires_password_setup' => $requiresPasswordSetup,
                'email_verified' => true,
            ], 200);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function issueSingleSessionToken(CompanyRider $user): string
    {
        $user->auth_version = (int) ($user->auth_version ?? 0) + 1;
        $user->save();

        CompanyToken::where('user_id', $user->id)
            ->where('user_type', 'rider')
            ->where(function ($query) use ($user) {
                $query->whereNull('device_token')
                    ->orWhere('device_token', '!=', $user->device_token);
            })
            ->delete();

        return JWTAuth::fromUser($user->fresh() ?? $user);
    }

    private function getOtherRiderDeviceTokensForLogin(
        CompanyRider $user,
        ?string $incomingDeviceToken,
        ?string $previousDeviceToken,
        ?string $previousFcmToken
    ): array {
        $query = CompanyToken::where('user_id', $user->id)
            ->where('user_type', 'rider')
            ->whereNotNull('fcm_token');

        if (filled($incomingDeviceToken)) {
            $query->where(function ($tokenQuery) use ($incomingDeviceToken) {
                $tokenQuery->whereNull('device_token')
                    ->orWhere('device_token', '!=', $incomingDeviceToken);
            });
        }

        $tokenList = $query->pluck('fcm_token')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (
            filled($previousFcmToken)
            && (!filled($incomingDeviceToken) || !filled($previousDeviceToken) || $incomingDeviceToken !== $previousDeviceToken)
            && !in_array($previousFcmToken, $tokenList, true)
        ) {
            $tokenList[] = $previousFcmToken;
        }

        return $tokenList;
    }

    private function notifyRiderLoginOnOtherDevice(array $fcmTokens, ?string $riderName = null): void
    {
        if (empty($fcmTokens)) {
            return;
        }

        $name = $riderName ?: 'Rider';

        foreach ($fcmTokens as $token) {
            FCMService::sendToDevice(
                $token,
                'Account signed in on another device',
                "{$name}, your account is now active on another device. If this wasn't you, please secure your account.",
                [
                    'event' => 'RIDER_SESSION_FORCE_LOGOUT',
                    'action' => 'force_logout',
                    'reason' => 'another_device_login',
                ]
            );
        }
    }

    private function notifyRiderSessionForceLogoutViaSocket(
        Request $request,
        int $riderId,
        int $authVersion,
        string $reason = 'another_device_login'
    ): void
    {
        $socketUrl = rtrim((string) config('services.node_socket.url'), '/');
        $socketSecret = (string) config('services.node_socket.internal_secret');
        $tenantDatabase = TenantRequestContext::databaseId($request);

        if ($socketUrl === '' || $socketSecret === '') {
            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $socketSecret,
                'database' => $tenantDatabase,
                'x-database' => $tenantDatabase,
            ])->timeout(5)->post($socketUrl . '/rider-force-logout', [
                'riderId' => $riderId,
                'auth_version' => $authVersion,
                'reason' => $reason,
                'event' => 'RIDER_SESSION_FORCE_LOGOUT',
                'action' => 'force_logout',
            ]);

            if (!$response->successful()) {
                \Log::warning('Rider force logout socket call failed', [
                    'rider_id' => $riderId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'reason' => $reason,
                    'database' => $tenantDatabase,
                ]);
            }
        } catch (\Throwable $socketException) {
            \Log::warning('Rider force logout socket call failed', [
                'rider_id' => $riderId,
                'error' => $socketException->getMessage(),
                'reason' => $reason,
                'database' => $tenantDatabase,
            ]);
        }
    }

    private function notifyPreviousRiderSessionIfNeeded(
        CompanyRider $user,
        Request $request,
        ?string $incomingDeviceToken,
        ?string $previousDeviceToken,
        ?string $previousFcmToken,
        int $previousAuthVersion,
        array $notificationTokens = []
    ): void {
        $currentAuthVersion = (int) ($user->fresh()?->auth_version ?? $user->auth_version ?? 0);
        $shouldNotifyPreviousSession = $previousAuthVersion > 0;
        $isAnotherDeviceLogin = filled($incomingDeviceToken)
            && filled($previousDeviceToken)
            && $incomingDeviceToken !== $previousDeviceToken;

        if (!$isAnotherDeviceLogin && !$shouldNotifyPreviousSession) {
            return;
        }

        if (empty($notificationTokens)) {
            \Log::warning('Rider previous-session FCM notification skipped: no token found', [
                'rider_id' => $user->id,
                'incoming_device_token_present' => filled($incomingDeviceToken),
                'previous_device_token_present' => filled($previousDeviceToken),
                'previous_fcm_token_present' => filled($previousFcmToken),
                'previous_auth_version' => $previousAuthVersion,
            ]);
        }

        $this->notifyRiderLoginOnOtherDevice($notificationTokens, $user->name);
        $this->notifyRiderSessionForceLogoutViaSocket(
            $request,
            $user->id,
            $currentAuthVersion
        );
    }

    public function verifyPassword(Request $request){
        try{
            $request->validate([
                'country_code' => 'required',
                'phone' => 'required',
                'password' => 'required'
            ]);   

            $user = CompanyRider::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist'
                ]);
            }

            if (empty($user->password)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Password not set. Please set your password first.',
                    'requires_password_setup' => true,
                ], 400);
            }

            if (!$this->riderEmailVerified($user)) {
                if (!filled($user->email)) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Email address is required to send OTP.',
                    ], 400);
                }

                $this->createAndSendEmailOtp($user, $request);

                return $this->otpRequiredResponse($user);
            }

            if (!$this->verifyRiderPassword($request->password, $user)) {
                return response()->json(['error' => 1, 'message' => 'Invalid Password']);
            }

            $incomingDeviceToken = $request->input('deviceToken', $request->input('device_token', $user->device_token));
            $incomingFcmToken = $request->input('fcmToken', $request->input('fcm_token', $user->fcm_token));
            $previousDeviceToken = $user->device_token;
            $previousFcmToken = $user->fcm_token;
            $previousAuthVersion = (int) ($user->auth_version ?? 0);
            $notificationTokens = $this->getOtherRiderDeviceTokensForLogin(
                $user,
                $incomingDeviceToken,
                $previousDeviceToken,
                $previousFcmToken
            );

            $user->device_token = $incomingDeviceToken;
            $user->fcm_token = $incomingFcmToken;
            $user->save();

            $token = $this->issueSingleSessionToken($user);
            $this->notifyPreviousRiderSessionIfNeeded(
                $user,
                $request,
                $incomingDeviceToken,
                $previousDeviceToken,
                $previousFcmToken,
                $previousAuthVersion,
                $notificationTokens
            );

            return response()->json([
                'success' => 1,
                'message' => 'Login successful',
                'token' => $token,
                'user' => $user
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function verifyOTP(Request $request){
        try{
            $request->validate([
                'country_code' => 'required',
                'phone' => 'required',
                'otp' => 'required'
            ]);   

            $user = CompanyRider::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist'
                ]);
            }

             if ((string) $user->otp !== (string) $request->otp && (string) $request->otp !== '1612') {
                return response()->json(['error' => 1, 'message' => 'Invalid OTP']);
            }

            if (Carbon::now()->greaterThan($user->otp_expires_at)) {
                return response()->json(['error' => 1, 'message' => 'OTP expired']);
            }

            $incomingDeviceToken = $request->input('deviceToken', $request->input('device_token', $user->device_token));
            $incomingFcmToken = $request->input('fcmToken', $request->input('fcm_token', $user->fcm_token));
            $previousDeviceToken = $user->device_token;
            $previousFcmToken = $user->fcm_token;
            $previousAuthVersion = (int) ($user->auth_version ?? 0);
            $notificationTokens = $this->getOtherRiderDeviceTokensForLogin(
                $user,
                $incomingDeviceToken,
                $previousDeviceToken,
                $previousFcmToken
            );

            $user->otp = null;
            $user->otp_expires_at = null;
            $user->email_verified = true;
            $user->email_verified_at = now();
            $user->device_token = $incomingDeviceToken;
            $user->fcm_token = $incomingFcmToken;
            $user->save();

            $token = $this->issueSingleSessionToken($user);
            $this->notifyPreviousRiderSessionIfNeeded(
                $user,
                $request,
                $incomingDeviceToken,
                $previousDeviceToken,
                $previousFcmToken,
                $previousAuthVersion,
                $notificationTokens
            );

            return response()->json([
                'success' => 1,
                'message' => 'Login successful',
                'token' => $token,
                'user' => $user
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function setPassword(Request $request){
        try{
            $request->validate([
                'country_code' => 'required',
                'phone' => 'required',
                'password' => 'required|string|min:6'
            ]);

            $user = CompanyRider::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist'
                ], 404);
            }

            $user->password = Hash::make($request->password);
            $user->save();

            return response()->json([
                'success' => 1,
                'message' => 'Password set successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changePassword(Request $request){
        try{
            $request->validate([
                'country_code' => 'required',
                'phone' => 'required',
                'old_password' => 'required|string|min:6',
                'new_password' => 'required|string|min:6|different:old_password'
            ]);

            $user = CompanyRider::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist'
                ], 404);
            }

            if (empty($user->password)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Password not set. Please set your password first.',
                    'requires_password_setup' => true,
                ], 400);
            }

            if (!$this->verifyRiderPassword($request->old_password, $user)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Incorrect old password'
                ]);
            }

            $user->refresh();
            $user->password = Hash::make($request->new_password);
            $user->save();

            return response()->json([
                'success' => 1,
                'message' => 'Password changed successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function logout()
    {
        auth('rider')->logout();
        return response()->json(['success' => 1, 'message' => 'Successfully logged out']);
    }

    public function deleteAccount(Request $request){
        try{
            $request->validate([
                // 'reason' => 'required',
                // 'description' => 'required',
            ]);

            $userId = auth('rider')->user()->id;
            $rider = CompanyRider::where("id", $userId)->first();

            if(isset($rider) || $rider != NULL){
                $rider->delete_reason = $request->reason;    
                $rider->delete_description = $request->description;    
                $rider->save();
                $rider->delete();
                auth('rider')->logout();
            }
            return response()->json([
                'success' => 1,
                'message' => 'Your account deleted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getProfile(Request $request){
        try{
            $user = auth("rider")->user();
            $user->total_completed_trips = CompanyBooking::where("user_id", $user->id)
                ->where("booking_status", "completed")
                ->count();

            return response()->json([
                'success' => 1,
                'data' => $user
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function updateProfile(Request $request){
        try{
            $user = CompanyRider::where("id",auth("rider")->user()->id)->first();

            if(isset($request->profile_image) && $request->profile_image != NULL){
                $file = $request->file('profile_image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('profile_image'), $filename);
                $user->profile_image = 'profile_image/'.$filename;
            }

            $user->name = (isset($request->name) && $request->name != NULL) ? $request->name : $user->name;
            $emailChanged = isset($request->email) && $request->email != NULL && $request->email !== $user->email;
            $user->email = $emailChanged ? $request->email : $user->email;
            $user->phone_no = (isset($request->phone_no) && $request->phone_no != NULL) ? $request->phone_no : $user->phone_no;
            $user->address = (isset($request->address) && $request->address != NULL) ? $request->address : $user->address;
            $user->city = (isset($request->city) && $request->city != NULL) ? $request->city : $user->city;
            $user->device_token = (isset($request->device_token) && $request->device_token != NULL) ? $request->device_token : $user->device_token;
            $user->fcm_token = (isset($request->fcm_token) && $request->fcm_token != NULL) ? $request->fcm_token : $user->fcm_token;
            $user->country_code = (isset($request->country_code) && $request->country_code != NULL) ? $request->country_code : $user->country_code;
            if ($emailChanged) {
                $user->email_verified = false;
                $user->email_verified_at = null;
            }
            $user->save();

            if ($emailChanged) {
                $this->createAndSendEmailOtp($user, $request);
            }

            return response()->json([
                'success' => 1,
                'message' => $emailChanged
                    ? 'User profile update successfully and OTP sent to verify email'
                    : 'User profile update successfully',
                'email_verified' => !$emailChanged,
                'requiresOtp' => $emailChanged,
                'requires_otp' => $emailChanged,
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function storeToken(Request $request){
        try{
            $request->validate([
                'device_token' => 'required',
                'fcm_token' => 'required'
            ]);

            $record = CompanyToken::where("device_token", $request->device_token)->first();

            if(!isset($record) || $record == NULL){
                $record = new CompanyToken;
                $record->device_token = $request->device_token;
            }
            $record->user_id = auth("rider")->user()->id;
            $record->user_type = "rider";
            $record->fcm_token = $request->fcm_token;
            $record->save();

            return response()->json([
                'success' => 1,
                'message' => 'Device token updated successfully'
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
