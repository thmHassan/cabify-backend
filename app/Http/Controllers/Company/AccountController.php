<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyAccount;
use App\Models\CompanyBooking;

class AccountController extends Controller
{
    public function createAccount(Request $request){
        try{
            $request->validate([
                'name' => 'required',
                'email' => 'required',
                'phone_no' => 'required',
                'address' => 'required',
            ]);

            $new = new CompanyAccount;
            $new->name = $request->name;
            $new->email = $request->email;
            $new->phone_no = $request->phone_no;
            $new->company = $request->company;
            $new->address = $request->address;
            $new->notes = $request->notes;
            $new->save();

            return response()->json([
                'success' => 1,
                'message' => 'Account saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'success' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editAccount(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'name' => 'required',
                'email' => 'required',
                'phone_no' => 'required',
                'address' => 'required',
            ]);

            $new = CompanyAccount::where("id", $request->id)->first();
            $new->name = $request->name;
            $new->email = $request->email;
            $new->phone_no = $request->phone_no;
            $new->company = $request->company;
            $new->address = $request->address;
            $new->notes = $request->notes;
            $new->save();

            return response()->json([
                'success' => 1,
                'message' => 'Account updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'success' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getEditAccount(Request $request){
        try{
            $request->validate([
                'id' => 'required'
            ]);

            $data = CompanyAccount::where("id", $request->id)->first();

            return response()->json([
                'success' => 1,
                'data' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'success' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteAccount(Request $request){
        try{
            $request->validate([
                'id' => 'required'
            ]);

            $data = CompanyAccount::where("id", $request->id)->first();

            if(isset($data) && $data != NULL){
                $data->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => "Account deleted successfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'success' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listAccount(Request $request){
        try{
            $query = CompanyAccount::orderBy("id","DESC");

            if(isset($request->search) && $request->search != NULL){
                $query->where("name", "LIKE", "%". $request->search. "%");
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

    public function accountRideHistory(Request $request){
        try{
            $bookings = CompanyBooking::where("account", $request->account_id)->where("account_payment", "no")->get();
            
            $totalAmount = $bookings->sum("booking_amount");

            return response()->json([
                'success' => 1,
                'data' => $bookings,
                'totalAmount' => $totalAmount
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'success' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function collectAccountAmount(Request $request){
        try{
            CompanyBooking::where("account", $request->account_id)
                ->where("account_payment", "no")
                ->update([
                    'account_payment' => 'yes'
                ]);

            return response()->json([
                'success' => 1,
                'message' => 'Amount collected successfully'
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
