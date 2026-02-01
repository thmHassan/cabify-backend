<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyBooking;
use App\Models\CompanyRating;
use App\Models\CompanyBid;
use App\Models\CompanySetting;
use App\Models\CompanyDriver;
use App\Models\CompanyWaitingTimeLog;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class BookingController extends Controller
{
    public function completedRide(Request $request){
        try{
            $query = CompanyBooking::where("booking_status", "completed")->where("driver", auth('driver')->user()->id);
            if(isset($request->date) && $request->date != NULL){
                $query->whereDate("booking_date", $request->date);
            }
            $completedRides = $query->orderBy("booking_date", "DESC")->paginate(10);

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
            $cancelledRides = $query->orderBy("booking_date", "DESC")->paginate(10);

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
            $pendingRides = $query->orderBy("booking_date", "DESC")->paginate(10);

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
            $rideDetail = COmpanyBooking::where("id", $request->ride_id)->first();

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

            $newBid = new CompanyBid;
            $newBid->booking_id = $request->booking_id;
            $newBid->driver_id = auth("driver")->user()->id;
            $newBid->amount = $request->amount;
            $newBid->save();

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

            $booking = CompanyBooking::where("id", $request->booking_id)->first();

            if(isset($booking) && $booking != NULL){
                $booking->booking_status = "cancelled";
                $booking->cancel_reason = $request->cancel_reason;
                $booking->cancelled_by = 'driver';
                $booking->save();

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                ])->post(env('NODE_SOCKET_URL') . '/change-ride-status', [
                    'user' => $booking->user_id,
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

            }

            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $driver->driving_status = "idle";
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
                'user' => $booking->user_id,
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
                $booking->save();
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
            $booking->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/change-ride-status', [
                'user' => $booking->user_id,
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
