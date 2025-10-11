<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use App\Models\Setting;

class HomeController extends Controller
{
    public function updateProfile(Request $request){
        try{
            $request->validate([
                'name' => 'required',
                'email' => 'required',
                'profile_picture' => 'image|mimes:jpg,jpeg,png,gif|max:2048', 
            ]);
            
            $me = User::where('role','superadmin')->first();
            $me->name = $request->name;
            $me->email = $request->email;

            if(isset($request->profile_picture) && $request->profile_picture != NULL && $me->profile_picture && file_exists($me->profile_picture)) {
                unlink(public_path('profile_pictures/'.$me->profile_picture));
            }

            if(isset($request->profile_picture) && $request->profile_picture != NULL){
                $file = $request->file('profile_picture');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('profile_pictures'), $filename);
                $me->profile_picture = public_path('profile_pictures').'/'.$filename;
            }

            $me->save();

            return response()->json([
                'success' => 1,
                'message' => 'Profile updated successfully'
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changePassword(Request $request){
        try{
            $request->validate([
                'old_password' => 'required',
                'new_password' => 'required',
            ]);

            $me = User::where('role', 'superadmin')->first();

            if (!Hash::check($request->old_password, $me->password)) {
                return response()->json([
                    'message' => 'Old password is incorrect.'
                ], 400);
            }

            $me->password = Hash::make($request->new_password);
            $me->save();

            return response()->json([
                'success' => 1,
                'message' => 'Password changed successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function dashboard(){
        try{
            $totalCompanies = Tenant::count();
            $activeSubscription = 2;
            $monthlyRevenue = 100;
            $recentTransaction = [];
            $APIStatus['google_map']['requests'] = 1;  
            $APIStatus['google_map']['cost'] = 1;  
            $APIStatus['google_map']['status'] = 1;  
            $APIStatus['twillio_api']['minutes'] = 1;  
            $APIStatus['twillio_api']['cost'] = 1;  
            $APIStatus['twillio_api']['status'] = 1;  

            return response()->json([
                'success' => 1,
                'data' => [
                    'totalCompanies' => $totalCompanies,
                    'activeSubscription' => $activeSubscription,
                    'monthlyRevenue' => $monthlyRevenue,
                    'recentTransaction' => $recentTransaction,
                    'apiStatus' => $APIStatus
                ]
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function usageMonitoring(){
        try{
            $activeCompanies = Tenant::where('data->status', 'active')->count();
            $totalAPICalls =  19;
            $companyList = [
                [
                    'company_name' => 'ABC',
                    'api_calls_today' => '12500',
                    'map_request' => '8500',
                    'voip_minutes' => '1250',
                    'dispatchers' => '5/10'
                ],
                [
                    'company_name' => 'ABC',
                    'api_calls_today' => '12500',
                    'map_request' => '8500',
                    'voip_minutes' => '1250',
                    'dispatchers' => '5/10'
                ],
                [
                    'company_name' => 'ABC',
                    'api_calls_today' => '12500',
                    'map_request' => '8500',
                    'voip_minutes' => '1250',
                    'dispatchers' => '5/10'
                ],
                [
                    'company_name' => 'ABC',
                    'api_calls_today' => '12500',
                    'map_request' => '8500',
                    'voip_minutes' => '1250',
                    'dispatchers' => '5/10'
                ]
            ];

            return response()->json([
                'success' => 1,
                'data' => [
                    'activeCompanies' => $activeCompanies,
                    'totalAPICalls' => $totalAPICalls
                ],
                'company_list' => $companyList
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getAPIKeys(){
        try{
            $settingKeys = Setting::orderBy("id", "DESC")->first();
            return response()->json([
                'success' => 1,
                'settingKeys' => $settingKeys
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function storeAPIKeys(Request $request){
        try{
            $settingKeys = Setting::orderBy("id", "DESC")->first();
            if(!isset($settingKeys) || $settingKeys == NULL){
                $settingKeys = new Setting;
            }
            $settingKeys->stripe_secret = $request->stripe_secret;
            $settingKeys->stripe_key = $request->stripe_key;
            $settingKeys->barikoi_key = $request->barikoi_key;
            $settingKeys->google_map_key = $request->google_map_key;
            $settingKeys->firebase_key = $request->firebase_key;
            $settingKeys->smtp_host = $request->smtp_host;
            $settingKeys->smtp_user_name = $request->smtp_user_name;
            $settingKeys->smtp_password = $request->smtp_password;
            $settingKeys->smtp_from_address = $request->smtp_from_address;
            $settingKeys->save();

            return response()->json([
                'success' => 1,
                'message' => 'API keys saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
