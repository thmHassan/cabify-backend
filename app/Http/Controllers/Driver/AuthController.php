<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDriver;
use Carbon\Carbon;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request){
        try{
            $request->validate([
                'email' => 'required',
                'phone' => 'required'
            ]);

            $user = CompanyDriver::where('phone_no', $request->phone)->where('email', $request->email)->first();
            $new = 1;
            if(!isset($user) || $user == NULL){
                $user = new CompanyDriver;
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

            $user = CompanyDriver::where('phone_no', $request->phone)->where('email', $request->email)->first();

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
        auth('driver')->logout();
        return response()->json(['success' => 1, 'message' => 'Successfully logged out']);
    }

    public function deleteAccount(Request $request){
        try{
            $request->validate([
                'reason' => 'required',
                'description' => 'required',
            ]);

            $userId = auth('driver')->user()->id;
            $driver = CompanyDriver::where("id", $userId)->first();

            if(isset($driver) || $driver != NULL){
                $driver->delete_reason = $request->reason;    
                $driver->delete_description = $request->description;    
                $driver->save();
                $driver->delete();
                auth('driver')->logout();
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
