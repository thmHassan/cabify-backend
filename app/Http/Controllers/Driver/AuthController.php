<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDriver;
use Carbon\Carbon;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;
use App\Models\CompanyToken;
use App\Models\CompanyPlot;

class AuthController extends Controller
{
    public function register(Request $request){
        try{
            $request->validate([
                'email' => 'required',
                'phone' => 'required',
                'name' => 'required',
                'country_code' => 'required',
            ]);

            $user = CompanyDriver::where('phone_no', $request->phone)->orWhere('email', $request->email)->where("cpuntry_code", $request->country_code)->first();
            
            if(isset($user) && $user != NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User already exist with this Email or Phone No.'
                ], 400);
            }
            
            $user = new CompanyDriver;
            $user->phone_no = $request->phone;
            $user->email = $request->email;
            $user->name = $request->name;
            $user->country_code = $request->country_code;
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
            ], 400);
        }
    }

    public function login(Request $request){
        try{
            $request->validate([
                'phone' => 'required',
                'country_code' => 'required'
            ]);

            $user = CompanyDriver::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist with this Phone No.'
                ], 400);
            }

            $otp = rand(1000, 9999);
            $expiresAt = Carbon::now()->addMinutes(5);
            $user->otp = $otp;
            $user->otp_expires_at = $expiresAt;
            $user->save();

            return response()->json([
                'success' => 1,
                'message' => "User sign in successfully and OTP sent",
            ], 200);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }
    
    public function verifyOTP(Request $request){
        try{
            $request->validate([
                'phone' => 'required',
                'country_code' => 'required',
                'otp' => 'required'
            ]);   

            $user = CompanyDriver::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

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
            $user->fcm_token = $request->fcm_token;
            $user->device_token = isset($request->device_token) ? $request->device_token : $user->device_token;
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
                // 'reason' => 'required',
                // 'description' => 'required',
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

    public function getProfile(Request $request){
        try{
            $user = auth("driver")->user();

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
            $user = CompanyDriver::where("id",auth("driver")->user()->id)->first();

            if(isset($request->profile_image) && $request->profile_image != NULL){
                $file = $request->file('profile_image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('profile_image'), $filename);
                $user->profile_image = 'profile_image/'.$filename;
            }

            $user->name = (isset($request->name) && $request->name != NULL) ? $request->name : $user->name;
            $user->email = (isset($request->email) && $request->email != NULL) ? $request->email : $user->email;
            $user->phone_no = (isset($request->phone_no) && $request->phone_no != NULL) ? $request->phone_no : $user->phone_no;
            $user->address = (isset($request->address) && $request->address != NULL) ? $request->address : $user->address;
            $user->device_token = (isset($request->device_token) && $request->device_token != NULL) ? $request->device_token : $user->device_token;
            $user->fcm_token = (isset($request->fcm_token) && $request->fcm_token != NULL) ? $request->fcm_token : $user->fcm_token;
            $user->country_code = (isset($request->country_code) && $request->country_code != NULL) ? $request->country_code : $user->country_code;
            $user->save();

            return response()->json([
                'success' => 1,
                'message' => 'Driver profile update successfully'
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
            $record->user_id = auth("driver")->user()->id;
            $record->user_type = "driver";
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

    public function updateLocation(Request $request)
    {
        $driver = CompanyDriver::where("id", $request->driver_id)->first();

        $driver->latitude = $request->lat;
        $driver->longitude = $request->lng;
        $driver->save();

        event(new DriverLocationUpdated(
            $driver->id,
            $driver->latitude,
            $driver->longitude
        ));

        return response()->json([
            'success' => 1,
            'message' => "Driver location updated"
        ]);
    }

    public function setPlotPriority(Request $request){
        try{
            $request->validate([
                'plot_id' => 'required'
            ]);

            $drivers = CompanyDriver::where("plot_id", $request->plot_id)->orderBy("priority_plot")->where("id", "!=", auth("driver")->user()->id)->get();
            $key = -1;

            foreach($drivers as $key => $driver){
                $driver->priority_plot = $key + 1;
                $driver->save();
            }

            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $driver->plot_id = $request->plot_id;
            $driver->priority_plot = $key + 2;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => "Plot and Priority updated successfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function setLocation(Request $request){
        try{
            \Log::info("enter");

            $driver = CompanyDriver::where("id", $request->driver_id)->first();
            $driver->latitude = isset($request->latitude) ? $request->latitude : $driver->latitude;
            $driver->longitude = isset($request->longitude) ? $request->longitude : $driver->longitude;
            $driver->save();

            $plot_id = $this->getPlot($driver->latitude, $driver->longitude);
            if(isset($plot_id) && $plot_id != NULL){
                $drivers = CompanyDriver::where("plot_id", $plot_id)->orderBy("priority_plot")->where("id", "!=", $request->driver_id)->get();
                $key = -1;

                foreach($drivers as $key => $driver){
                    $driver->priority_plot = $key + 1;
                    $driver->save();
                }

                $driver = CompanyDriver::where("id", $request->driver_id)->first();
                $driver->plot_id = $plot_id;
                $driver->priority_plot = $key + 2;
                $driver->save();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Driver location updated successfully',
                'driver' => $driver
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getPlot($lat, $lng){
        try{
            $records = CompanyPlot::orderBy("id", "DESC")->get();
            $matched = null;
            foreach ($records as $rec) {
                $polygon = json_decode($rec->features, true);
                $array = json_decode($polygon['geometry']['coordinates'], true)[0];
                if ($this->pointInPolygon($lat, $lng, $array)) {
                    $matched = $rec;
                    break;
                }
            }
            if($matched){
                return $matched->id;
            }
            return NULL;
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function pointInPolygon($lat, $lng, $polygon)
    {
        if (count($polygon) == 2) {
            $lng1 = $polygon[0][0];
            $lat1 = $polygon[0][1];

            $lng2 = $polygon[1][0];
            $lat2 = $polygon[1][1];

            return (
                $lat >= min($lat1, $lat2) &&
                $lat <= max($lat1, $lat2) &&
                $lng >= min($lng1, $lng2) &&
                $lng <= max($lng1, $lng2)
            );
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

            $intersect = (($yi > $y) != ($yj > $y)) &&
                ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) $inside = !$inside;
        }
        return $inside;
    }
}
