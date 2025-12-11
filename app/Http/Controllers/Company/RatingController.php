<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyRating;

class RatingController extends Controller
{
    public function customerRatings(Request $request){
        try{
            $query = CompanyRating::where("user_type", 'user')->orderBy("id", "DESC")->with(['bookingDetail', 'bookingDetail.userDetail', 'bookingDetail.driverDetail']);

            if(isset($request->search) && $request->search != NULL){
                $query->where(function($q) use ($request){
                    $q->where("rating", "LIKE",  "%".$request->search."%")
                      ->orWhere("comment", "LIKE", "%".$request->search."%");
                });
            }
            $list = $query->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $list
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'success' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function driverRatings(Request $request){
        try{
            $query = CompanyRating::where("user_type", 'driver')->orderBy("id", "DESC")->with(['bookingDetail', 'bookingDetail.userDetail', 'bookingDetail.driverDetail']);

            if(isset($request->search) && $request->search != NULL){
                $query->where(function($q) use ($request){
                    $q->where("rating", "LIKE",  "%".$request->search."%")
                      ->orWhere("comment", "LIKE", "%".$request->search."%");
                });
            }
            $list = $query->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $list
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'success' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }
}
