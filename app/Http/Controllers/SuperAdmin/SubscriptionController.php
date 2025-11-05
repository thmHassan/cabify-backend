<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;

class SubscriptionController extends Controller
{
    public function subscriptionCards(){
        try{
            $data['active_subscription'] = '12';
            $data['monthly_revenue'] = '6800';
            $data['pending_renewals'] = '04';

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
                'features' => 'required',
            ]);

            $subscription = new Subscription;
            $subscription->plan_name = $request->plan_name;
            $subscription->billing_cycle = $request->billing_cycle;
            $subscription->amount = $request->amount;
            $subscription->features = implode(",",$request->features);
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
            $subscription->features = explode(",", $subscription->features);

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
                'features' => 'required',
            ]);

            $subscription = Subscription::where("id", $request->id)->first();
            $subscription->plan_name = $request->plan_name;
            $subscription->billing_cycle = $request->billing_cycle;
            $subscription->amount = $request->amount;
            $subscription->features = implode(",",$request->features);
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
}
