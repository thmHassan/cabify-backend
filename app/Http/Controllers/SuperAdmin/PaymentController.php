<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;

class PaymentController extends Controller
{
    public function paymentList(Request $request){
        try{
            $query = Transaction::orderBy("id", "DESC")->with('companyDetail');

            if(isset($request->search) && $request->search != NULL){
                $search = $request->search;
                $query->whereHas('companyDetail', function ($q) use ($search) {
                    $q->where('company_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            }

            if(isset($request->date) && $request->date != NULL){
                $search = $request->date;
                $query->whereDate("created_at", $request->date);
            }
            $list = $query->paginate(10);
            
            return response()->json([
                'success' => 1,
                'message' => 'List fetched successfully',
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
}
