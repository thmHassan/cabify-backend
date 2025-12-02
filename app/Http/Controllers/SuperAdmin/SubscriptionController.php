<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\Carbon;

class SubscriptionController extends Controller
{
    public function subscriptionCards(){
        try{
            $data['active_subscription'] = Tenant::where('data->expiry_date', '>=', Carbon::now()->format('Y-m-d'))->count();
            $data['monthly_revenue'] = Tenant::where('data->subscription_start_date', '>=', Carbon::now()->startOfMonth())->sum('data->payment_amount');
            $data['pending_renewals'] = Tenant::where('data->expiry_date', '<', Carbon::now()->format('Y-m-d'))->count();

            return response()->json([
                'success' => 1,
                'data' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function createSubscription(Request $request){
        try{
            $request->validate([
                'plan_name' => 'required|max:255',
                'billing_cycle' => 'required',
                'amount' => 'required',
                'billing_cycle_deduct_option' => 'required',
                'deduct_type' => 'required',
            ]);

            $subscription = new Subscription;
            $subscription->plan_name = $request->plan_name;
            $subscription->billing_cycle = $request->billing_cycle;
            $subscription->amount = $request->amount;
            $subscription->billing_cycle_deduct_option = $request->billing_cycle_deduct_option;
            $subscription->deduct_type = $request->deduct_type;
            // $subscription->features = implode(",",$request->features);
            $subscription->save();

            return response()->json([
                'success' => 1,
                'message' => 'Subscription created successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getEditSubscription(Request $request){
        try{
            $request->validate([
                'id' => 'required'
            ]);
            
            $subscription = Subscription::where("id", $request->id)->first();
            // $subscription->features = explode(",", $subscription->features);

            return response()->json([
                'success' => 1,
                'data' => $subscription
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editSubscription(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'plan_name' => 'required|max:255',
                'billing_cycle' => 'required',
                'amount' => 'required',
                'billing_cycle_deduct_option' => 'required',
                'deduct_type' => 'required',
            ]);

            $subscription = Subscription::where("id", $request->id)->first();
            $subscription->plan_name = $request->plan_name;
            $subscription->billing_cycle = $request->billing_cycle;
            $subscription->amount = $request->amount;
            $subscription->billing_cycle_deduct_option = $request->billing_cycle_deduct_option;
            $subscription->deduct_type = $request->deduct_type;
            // $subscription->features = implode(",",$request->features);
            $subscription->save();

            return response()->json([
                'success' => 1,
                'message' => 'Subscription updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function subscriptionList(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $subscriptionList = Subscription::orderBy("id","DESC");
            if(isset($request->search) && $request->search != NULL){
                $subscriptionList->where(function($query) use ($request){
                    $query->where("plan_name", "LIKE" ,"%".$request->search."%");
                });
            }
            $data = $subscriptionList->paginate($perPage);
            return response()->json([
                'success' => 1,
                'list' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function subscriptionManagement(Request $request){
        try{
            $activeSubscription = Tenant::where('data->expiry_date', '>=', Carbon::now()->format('Y-m-d'))->orderBy('created_at','DESC')->paginate(10);
            return response()->json([
                'success' => 1,
                'list' => $activeSubscription
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function pendingSubscription(Request $request){
        try{
            $pendingSubscription = Tenant::where('data->expiry_date', '<', Carbon::now()->format('Y-m-d'))->orderBy('created_at','DESC')->paginate(10);
            return response()->json([
                'success' => 1,
                'list' => $pendingSubscription
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteSubscription(Request $request){
        try{
            $data = Subscription::where("id", $request->id)->first();

            if(isset($data) && $data != NULL){
                $data->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Subscription deleted successfully'
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
