<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyBooking;

class RevenueFinanceController extends Controller
{
    public function getFinancialSummary(Request $request){
        try{
            $ridesEarning = CompanyBooking::where("booking_status", 'completed');
            $jobOffered = CompanyBooking::orderBy("id", "DESC");
            $jobAccepted = CompanyBooking::where("booking_status", 'completed');
                    
            if(isset($request->sub_company_id) && $request->sub_company_id != 'all'){
                $ridesEarning->where("sub_company", $request->sub_company_id);
                $jobOffered->where("sub_company", $request->sub_company_id);
                $jobAccepted->where("sub_company", $request->sub_company_id);
            }

            if(isset($request->start_date) && $request->start_date != NULL){
                $start = date("Y-m-d", strtotime($request->start_date));
                $end = date("Y-m-d", strtotime($request->end_date));
                $ridesEarning->whereBetween('booking_date', [$start, $end]);
                $jobOffered->whereBetween('booking_date', [$start, $end]);
                $jobAccepted->whereBetween('booking_date', [$start, $end]);
            }

            $ridesEarningCount = $ridesEarning->count();            
            $jobOfferedCount = $jobOffered->count();            
            $jobAcceptedCount = $jobAccepted->count();            

            return response()->json([
                'success' => 1,
                'ridesEarning' => $ridesEarningCount,
                'jobOfferedCount' => $jobOfferedCount,
                'jobAcceptedCount' => $jobAcceptedCount,
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e
            ]);
        }
    }

    public function getRideHistory(Request $request){
        try{
            $rides = CompanyBooking::where("booking_status", 'completed')->with("driverDetail")->orderBy("id", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'rides' => $rides
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e
            ]);
        }
    }
}
