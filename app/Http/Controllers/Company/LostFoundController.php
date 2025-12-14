<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyLostFound;

class LostFoundController extends Controller
{
    public function listLostFound(Request $request){
        try{
            $query = CompanyLostFound::orderBy("id", "DESC")->with(["bookingDetails", "bookingDetails.userDetail", "bookingDetails.driverDetail"]);

            if(isset($request->status) && $request->status != NULL){
                $query->where("status", $request->status);
            }
            if(!empty($request->dispatcher_id)) {
                $query->whereHas('bookingDetails', function ($q) use ($request) {
                    $q->where('dispatcher_id', $request->dispatcher_id);
                });
            }
            if(isset($request->search) && $request->search != NULL){
                $query->where(function($q) use ($request){
                    $q->where("created_at", "LIKE", "%".$request->search."%");
                    $q->orWhere("descrition", "LIKE", "%".$request->search."%");
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
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeStatusLostFound(Request $request){
        try{
            $lostFound = CompanyLostFound::where("id", $request->id)->first();
            $lostFound->status = $request->status;
            $lostFound->save();

            return response()->json([
                'success' => 1,
                'message' => 'Status updated successully'
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
