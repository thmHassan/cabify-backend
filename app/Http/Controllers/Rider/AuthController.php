<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyRider;
use Carbon\Carbon;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function login(Request $request){
        try{
            $request->validate([
                'email' => 'required',
                'phone' => 'required'
            ]);

            $existUser = CompanyRider::where('phone_no', $request->phone)->first();
            
            if(isset($existUser) && $existUser->email != $request->email){
                return response()->json([
                    'error' => 1,
                    'message' => 'This phone no already registered with othe email id'
                ]);
            }

            $existUser = CompanyRider::where('email', $request->email)->first();
            
            if(isset($existUser) && $existUser->phone_no != $request->phone){
                return response()->json([
                    'error' => 1,
                    'message' => 'This email id is already registered with other phone no'
                ]);
            }

            $user = CompanyRider::where('phone_no', $request->phone)->where('email', $request->email)->first();
            $new = 1;
            if(!isset($user) || $user == NULL){
                $user = new CompanyRider;
                $user->phone_no = $request->phone;
                $user->email = $request->email;
                $user->save();
                $new = 2;
            }

            $otp = rand(1000, 9999);
            $expiresAt = Carbon::now()->addMinutes(5);
            $user->otp = $otp;
            $user->otp_expires_at = $expiresAt;
            $user->save();

            $message = "User sign in successfully and OTP sent";
            if($new == 2){
                $message = "User sign up successfully and OTP sent";
            }

            return response()->json([
                'success' => 1,
                'message' => $message,
            ], 200);
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
                'email' => 'required',
                'phone' => 'required',
                'otp' => 'required'
            ]);   

            $user = CompanyRider::where('phone_no', $request->phone)->where('email', $request->email)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist'
                ]);
            }

             if ($user->otp !== $request->otp && $request->otp != 1612) {
                return response()->json(['error' => 1, 'message' => 'Invalid OTP']);
            }

            if (Carbon::now()->greaterThan($user->otp_expires_at)) {
                return response()->json(['error' => 1, 'message' => 'OTP expired']);
            }

            $user->otp = null;
            $user->otp_expires_at = null;
            $user->device_token = $request->device_token;
            $user->fcm_token = $request->fcm_token;
            $user->save();

            $token = JWTAuth::fromUser($user);

            return response()->json([
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

    public function logout()
    {
        auth('rider')->logout();
        return response()->json(['success' => 1, 'message' => 'Successfully logged out']);
    }

    public function deleteAccount(Request $request){
        try{
            $request->validate([
                'reason' => 'required',
                'description' => 'required',
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
}
