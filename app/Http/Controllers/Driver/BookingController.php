<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyBooking;
use App\Models\CompanyRating;

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
}
