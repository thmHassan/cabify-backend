<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyBooking;
use App\Models\CompanyRating;
use App\Models\CompanyBid;
use App\Models\CompanySetting;
use App\Models\CompanyDriver;
use App\Models\VehicleType;
use App\Models\CompanyWaitingTimeLog;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Models\CompanyToken;
use App\Services\FCMService;
use App\Models\CompanyNotification;
use App\Models\Setting;
use App\Models\TenantUser;

class BookingController extends Controller
{
    public function completedRide(Request $request){
        try{
            $query = CompanyBooking::where("booking_status", "completed")->where("driver", auth('driver')->user()->id);
            if(isset($request->date) && $request->date != NULL){
                $query->where("booking_date", $request->date);
            }
            $completedRides = $query->with(['userDetail', 'driverDetail','ratingDetail'])->orderBy("booking_date", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $completedRides
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function cancelledRide(Request $request){
        try{
            $query = CompanyBooking::where("booking_status", "cancelled")->where("driver", auth('driver')->user()->id);
            if(isset($request->date) && $request->date != NULL){
                $query->whereDate("booking_date", $request->date);
            }
            $cancelledRides = $query->with(['userDetail', 'driverDetail', 'ratingDetail'])->orderBy("booking_date", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $cancelledRides
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function upcomingRide(Request $request){
        try{
            $query = CompanyBooking::where("booking_status", "pending")->where("driver", auth('driver')->user()->id);
            if(isset($request->date) && $request->date != NULL){
                $query->whereDate("booking_date", $request->date);
            }
            $pendingRides = $query->with(['userDetail', 'driverDetail','ratingDetail'])->orderBy("booking_date", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $pendingRides
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function rateRide(Request $request){
        try{
            $request->validate([
                'booking_id' => 'required',
                'rating' => 'required'
            ]);

            $rating = new CompanyRating;
            $rating->booking_id = $request->booking_id;
            $rating->user_type = "driver";
            $rating->rating = $request->rating;
            $rating->save();

            return response()->json([
                'success' => 1,
                'message' => 'Customer ratings given successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function listRideForBidding(Request $request){
        try{
            $rideList = CompanyBooking::whereNull("driver")->where("pickup_time", "asap")->where("booking_status", "pending")->orderBy("id", "DESC")->with("userDetail")->get();

            return response()->json([
                'success' => 1,
                'list' => $rideList
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function rideDetail(Request $request){
        try{
            $rideDetail = CompanyBooking::where("id", $request->ride_id)->with(['userDetail', 'ratingDetail'])->first();

            return response()->json([
                'success' => 1,
                'rideDetail' => $rideDetail
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function placeBid(Request $request){
        try{

            $request->validate([
                'booking_id' => 'required',
                'amount' => 'required'
            ]);

            $latestPackages = Package::where("driver_id", auth("driver")->user()->id)->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('packages')
                    ->where("driver_id", auth("driver")->user()->id)
                    ->groupBy('package_type');
            })->get();

            $canAcceptRide = false;
            foreach($latestPackages as $package){
                if($package->package_type == "per_ride_commission_topup"){
                    if($package->pending_rides >= 1){
                        $package->pending_rides -= 1;
                        $package->save();
                        $canAcceptRide = true;
                        break;
                    }
                }
                elseif($package->package_type == "per_ride_commission_potpaid"){
                    if($package->expire_date >= date("Y-m-d")){
                        $canAcceptRide = true;
                        break;
                    }
                }
                elseif($package->package_type == "packages_postpaid"){
                    if($package->expire_date >= date("Y-m-d")){
                        $canAcceptRide = true;
                        break;
                    }
                }
                elseif($package->package_type == "packages_topup"){
                    if($package->expire_date >= date("Y-m-d")){
                        $canAcceptRide = true;
                        break;
                    }
                }
            }

            if(! $canAcceptRide){
                return response()->json([
                    'error' => 1,
                    'message' => 'Your package validity to accept ride is over'
                ], 400);
            }

            $newBid = new CompanyBid;
            $newBid->booking_id = $request->booking_id;
            $newBid->driver_id = auth("driver")->user()->id;
            $newBid->amount = $request->amount;
            $newBid->save();

            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            $vehicle = VehicleType::where("id", auth("driver")->user()->vehicle_type)->first();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/place-bid', [
                'userId' => $booking->user_id,
                'bid' => [
                    'amount' => $newBid->amount,
                    'driver_name' => auth("driver")->user()->name,
                    'profile_image' => auth("driver")->user()->profile_image,
                    'vehicle_name' => auth("driver")->user()->vehicle_name,
                    'vehicle_type' => $vehicle->vehicle_type_name,
                    'rating' => auth("driver")->user()->rating,
                    'bid_id' => $newBid->id
                ]
            ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if(isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){
                $notification = new CompanyNotification;
                $notification->user_type = "rider";
                $notification->user_id = $booking->user_id;
                $notification->title = 'New Bid';
                $notification->message = 'New bid is placed by driver';
                $notification->save();
    
                $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();
    
                if(isset($tokens) && $tokens != NULL){
                    foreach($tokens as $key => $token){
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            'New Bid',
                            'New bid is placed by driver',
                            [
                                'bid_id' => $newBid->id,
                            ]
                        );
                    }
                }
            }

            return response()->json([
                'success' => 1,
                'message' => 'Bid placed succesfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function cancelConfirmRide(Request $request){
        try{
            $request->validate([
                'booking_id' => 'required',
                'cancel_reason' => 'required',
            ]);

            $setting = CompanySetting::orderBy("id", "DESC")->first();

            if($setting->cancellation_per_day <= auth("driver")->user()->cancel_rides_per_day){
                return response()->json([
                    'error' => 1,
                    'message' => "You have reached cancellation limit per day"
                ], 400);
            }

            $booking = CompanyBooking::where("id", $request->booking_id)->first();

            if(isset($booking) && $booking != NULL){
                $booking->booking_status = "cancelled";
                $booking->cancel_reason = $request->cancel_reason;
                $booking->cancelled_by = 'driver';
                $booking->save();

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                ])->post(env('NODE_SOCKET_URL') . '/change-ride-status', [
                    'userId' => $booking->user_id,
                    'status' => "cancel_confirm_ride",
                    'booking' => [
                        'id' => $booking->id,
                        'booking_id' => $booking->booking_id,
                        'pickup_point' => $booking->pickup_point,
                        'destination_point' => $booking->destination_point,
                        'offered_amount' => $booking->offered_amount,
                        'distance' => $booking->distance,
                        'booking_status' => $booking->booking_status
                    ]
                ]);

                $dataCheck = (new TenantUser)
                    ->setConnection('central')
                    ->where("id", $request->header('database'))
                    ->first();

                if(isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){
                    $notification = new CompanyNotification;
                    $notification->user_type = "rider";
                    $notification->user_id = $booking->user_id;
                    $notification->title = 'Cancel Ride';
                    $notification->message = 'Your ride has been cancelled by driver';
                    $notification->save();

                    $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();

                    if(isset($tokens) && $tokens != NULL){
                        foreach($tokens as $key => $token){
                            FCMService::sendToDevice(
                                $token->fcm_token,
                                'Cancel Ride',
                                'Your ride has been cancelled by driver',
                                [
                                    'booking_id' => $booking->id,
                                ]
                            );
                        }
                    }
                }
            }

            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $driver->driving_status = "idle";
            $driver->cancel_rides_per_day += 1;
            $driver->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/waiting-driver', [
                'clientId' => $request->header('database'),
                'driverName' => auth("driver")->user()->name,
                'plot' => auth("driver")->user()->plot_id,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Ride cancelled succesfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function acceptRide(Request $request){
        try{

            $latestPackages = Package::where("driver_id", auth("driver")->user()->id)->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('packages')
                    ->where("driver_id", auth("driver")->user()->id)
                    ->groupBy('package_type');
            })->get();

            $canAcceptRide = false;
            foreach($latestPackages as $package){
                if($package->package_type == "per_ride_commission_topup"){
                    if($package->pending_rides >= 1){
                        $package->pending_rides -= 1;
                        $package->save();
                        $canAcceptRide = true;
                        break;
                    }
                }
                elseif($package->package_type == "per_ride_commission_potpaid"){
                    if($package->expire_date >= date("Y-m-d")){
                        $canAcceptRide = true;
                        break;
                    }
                }
                elseif($package->package_type == "packages_postpaid"){
                    if($package->expire_date >= date("Y-m-d")){
                        $canAcceptRide = true;
                        break;
                    }
                }
                elseif($package->package_type == "packages_topup"){
                    if($package->expire_date >= date("Y-m-d")){
                        $canAcceptRide = true;
                        break;
                    }
                }
            }

            if(! $canAcceptRide){
                return response()->json([
                    'error' => 1,
                    'message' => 'Your package validity to accept ride is over'
                ], 400);
            }

            $booking = CompanyBooking::where("id", $request->ride_id)->with('userDetail')->first();
            $booking->booking_status = "ongoing";
            $booking->booking_amount = $booking->offered_amount;
            $booking->driver = auth("driver")->user()->id;
            $booking->save();

            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $driver->driving_status = "busy";
            $driver->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/change-ride-status', [
                'userId' => $booking->user_id,
                'status' => "accept_ride",
                'booking' => [
                    'id' => $booking->id,
                    'booking_id' => $booking->booking_id,
                    'pickup_point' => $booking->pickup_point,
                    'destination_point' => $booking->destination_point,
                    'offered_amount' => $booking->offered_amount,
                    'distance' => $booking->distance,
                    'user_id' => $booking->user_id,
                    'user_name' => $booking->name,
                    'user_profile' => $booking->userDetail->profile_image,
                    'pickup_location' => $booking->pickup_location,
                    'destination_location' => $booking->destination_location,
                ]
            ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if(isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){
                $notification = new CompanyNotification;
                $notification->user_type = "rider";
                $notification->user_id = $booking->user_id;
                $notification->title = 'Accept Ride';
                $notification->message = 'Your ride has been accepted by driver';
                $notification->save();

                $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();

                if(isset($tokens) && $tokens != NULL){
                    foreach($tokens as $key => $token){
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            'Accept Ride',
                            'Your ride has been accepted by driver',
                            [
                                'booking_id' => $booking->id,
                            ]
                        );
                    }
                }
            }

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/on-job-driver', [
                'clientId' => $request->header('database'),
                'driverName' => auth("driver")->user()->name,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Ride accepted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function currentRide(Request $request){
        try{
            $booking = CompanyBooking::where("driver", auth('driver')->user()->id)
                        ->where(function($q){
                            $q->where("booking_status", 'started')
                              ->orWhere("booking_status", 'arrived')
                              ->orWhere("booking_status", 'ongoing');
                        })->with(['userDetail', 'vehicleDetail'])->first();

            return response()->json([
                'success' => 1,
                'currentRide' => $booking
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function arrivedStatus(Request $request){
        try{
            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            $booking->booking_status = "arrived";
            $booking->save();

            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $driver->driving_status = "busy";
            $driver->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/change-ride-status', [
                'userId' => $booking->user_id,
                'status' => "arrived_driver",
                'booking' => [
                    'id' => $booking->id,
                    'booking_id' => $booking->booking_id,
                    'pickup_point' => $booking->pickup_point,
                    'destination_point' => $booking->destination_point,
                    'offered_amount' => $booking->offered_amount,
                    'distance' => $booking->distance,
                    'booking_status' => $booking->booking_status
                ]
            ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if(isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){
                $notification = new CompanyNotification;
                $notification->user_type = "rider";
                $notification->user_id = $booking->user_id;
                $notification->title = 'Arrived Ride';
                $notification->message = 'Driver is arrived at your pickup location';
                $notification->save();
    
                $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();
    
                if(isset($tokens) && $tokens != NULL){
                    foreach($tokens as $key => $token){
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            'Arrived Driver',
                            'Driver is arrived at your pickup location',
                            [
                                'booking_id' => $booking->id,
                            ]
                        );
                    }
                }
            }

            return response()->json([
                'success' => 1,
                'message' => 'User received notification that you arrived'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function waitingTime(Request $request){
        try{
            $request->validate([
                'booking_id' => 'required'
            ]);

            $waitingRecord = CompanyWaitingTimeLog::where("booking_id", $request->booking_id)->where("status", 'start')->orderBy("id", "DESC")->first();

            if(!isset($waitingRecord) || $waitingRecord == NULL){
                $waitingRecord = new CompanyWaitingTimeLog;
                $waitingRecord->booking_id = $request->booking_id;
                $waitingRecord->start_time = date("Y-m-d H:i:s");
                $waitingRecord->status = "start";
                $waitingRecord->save();

                return response()->json([
                    'success' => 1,
                    'message' => 'Waiting time has started'
                ]);
            }
            else{
                $setting = CompanySetting::orderBy("id", "DESC")->first();
                $waitingRecord->end_time = date("Y-m-d H:i:s");
                $waitingRecord->status = "stop";
                $waitingRecord->save();

                $start = Carbon::parse($waitingRecord->start_time);
                $end   = Carbon::parse($waitingRecord->end_time);
                $waitingMinutes = $start->diffInMinutes($end);

                $amount = $waitingMinutes * $setting->waiting_time_charge;
                $booking = CompanyBooking::where("id", $request->booking_id)->first();
                $booking->booking_amount += $amount;
                $booking->waiting_time += $waitingMinutes;
                $booking->waiting_amount += $amount;
                $booking->save();
                $waitingRecord->charge = $amount;
                $waitingRecord->save();

                return response()->json([
                    'success' => 1,
                    'message' => 'Waiting time has stopped',
                    'updated_amount' => $booking->booking_amount
                ]);
            }
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeBookingPaymentStatus(Request $request){
        try{
            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            $booking->payment_status = "completed";
            $booking->save();

            return response()->json([
                'success' => 1,
                'message' => 'Payment status marked as completed'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function verifyBookingOtp(Request $request){
        try{
            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            if($booking->otp == $request->otp){
                $booking->booking_status = "started";
                $booking->driver_pickup_time = now()->format('Y-m-d H:i:s');
                $booking->save();

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                ])->post(env('NODE_SOCKET_URL') . '/change-ride-status', [
                    'userId' => $booking->user_id,
                    'status' => "ride_started",
                    'booking' => [
                        'id' => $booking->id,
                        'booking_id' => $booking->booking_id,
                        'pickup_point' => $booking->pickup_point,
                        'destination_point' => $booking->destination_point,
                        'offered_amount' => $booking->offered_amount,
                        'distance' => $booking->distance,
                        'booking_status' => $booking->booking_status
                    ]
                ]);

                $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

                if(isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){ 
                    $notification = new CompanyNotification;
                    $notification->user_type = "rider";
                    $notification->user_id = $booking->user_id;
                    $notification->title = 'Ride Start';
                    $notification->message = 'Your ride has been started to your destination';
                    $notification->save();

                    $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();

                    if(isset($tokens) && $tokens != NULL){
                        foreach($tokens as $key => $token){
                            FCMService::sendToDevice(
                                $token->fcm_token,
                                'Ride Start',
                                'Your ride has been started to your destination',
                                [
                                    'booking_id' => $booking->id,
                                ]
                            );
                        }
                    }
                }
                return response()->json([
                    'success' => 1,
                    'message' => 'OTP verified successfully'
                ]);
            }
            else{
                return response()->json([
                    'success' => 1,
                    'message' => 'OTP unverified'
                ], 400);
            }
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function completeCurrentRide(Request $request){
        try{
            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            $booking->booking_status = "completed";
            $booking->driver_dropoff_time = now()->format('Y-m-d H:i:s');
            $booking->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/change-ride-status', [
                'userId' => $booking->user_id,
                'status' => "complete_current_ride",
                'booking' => [
                    'id' => $booking->id,
                    'booking_id' => $booking->booking_id,
                    'pickup_point' => $booking->pickup_point,
                    'destination_point' => $booking->destination_point,
                    'offered_amount' => $booking->offered_amount,
                    'distance' => $booking->distance,
                    'booking_status' => $booking->booking_status
                ]
            ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if(isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){

                $notification = new CompanyNotification;
                $notification->user_type = "rider";
                $notification->user_id = $booking->user_id;
                $notification->title = 'Ride Completed';
                $notification->message = 'Your ride has been completed. Please rate application';
                $notification->save();

                $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();

                if(isset($tokens) && $tokens != NULL){
                    foreach($tokens as $key => $token){
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            'Ride completed',
                            'Your ride has been completed. Please rate application',
                            [
                                'booking_id' => $booking->id,
                            ]
                        );
                    }
                }
            }

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/waiting-driver', [
                'clientId' => $request->header('database'),
                'driverName' => auth("driver")->user()->name,
                'plot' => auth("driver")->user()->plot_id,
            ]);

            $driver = CompanyDriver::where("id", auth('driver')->user()->id)->first();
            $driver->driving_status = "idle";
            $driver->save();

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

            Mail::send('emails.ride-complete', [
                'name' => $booking->name ?? 'User',
                'pickup_location' => $booking->pickup_location,
                'dropoff_location' => $booking->destination_location,
                'ride_date' => $booking->booking_date,
                'total_fare' => $booking->booking_amount,
            ], function ($message) use ($booking) {
                $message->to($booking->email)
                        ->subject('Ride Completed');
            });

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

            Mail::send('emails.ride-complete', [
                'name' => auth("driver")->user()->name ?? 'User',
                'pickup_location' => $booking->pickup_location,
                'dropoff_location' => $booking->destination_location,
                'ride_date' => $booking->booking_date,
                'total_fare' => $booking->booking_amount,
            ], function ($message) {
                $message->to(auth("driver")->user()->email)
                        ->subject('Ride Completed');
            });

            return response()->json([
                'success' => 1,
                'message' => 'Ride completed successfully'
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
