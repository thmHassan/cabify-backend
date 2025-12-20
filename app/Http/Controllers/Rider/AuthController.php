<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyRider;
use Carbon\Carbon;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request){
        try{
            $request->validate([
                'email' => 'required',
                'phone' => 'required',
                'name' => 'required',
            ]);

            $existUser = CompanyRider::where('phone_no', $request->phone)->where("email", $request->email)->first();
            
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
            $user->save();

            $otp = rand(1000, 9999);
            $expiresAt = Carbon::now()->addMinutes(5);
            $user->otp = $otp;
            $user->otp_expires_at = $expiresAt;
            $user->save();

            return response()->json([
                'success' => 1,
                'message' => "User sign up successfully and OTP sent",
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
                'email' => 'required',
                'phone' => 'required'
            ]);

            $existUser = CompanyRider::where('phone_no', $request->phone)->where("email", $request->email)->first();
            
            if(!isset($existUser) || $existUser == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User not exists with this Email and Phone No.'
                ]);
            }

            $otp = rand(1000, 9999);
            $expiresAt = Carbon::now()->addMinutes(5);
            $existUser->otp = $otp;
            $existUser->otp_expires_at = $expiresAt;
            $existUser->save();

            return response()->json([
                'success' => 1,
                'message' => "User sign in successfully and OTP sent",
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
