<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use App\Models\Setting;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class HomeController extends Controller
{
    public function updateProfile(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required',
                'email' => 'required',
                'profile_picture' => 'image|mimes:jpg,jpeg,png,gif|max:2048',
            ]);

            $me = User::where('role', 'superadmin')->first();
            $me->name = $request->name;
            $me->email = $request->email;

            if (isset($request->profile_picture) && $request->profile_picture != NULL && $me->profile_picture && file_exists($me->profile_picture)) {
                unlink(public_path('profile_pictures/' . $me->profile_picture));
            }

            if (isset($request->profile_picture) && $request->profile_picture != NULL) {
                $file = $request->file('profile_picture');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('profile_pictures'), $filename);
                $me->profile_picture = 'profile_pictures/' . $filename;
            }

            $me->save();

            return response()->json([
                'success' => 1,
                'message' => 'Profile updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changePassword(Request $request)
    {
        try {
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
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function dashboard()
    {
        try {
            $totalCompanies = Tenant::where('data->created_at', '>=', Carbon::now()->subHours(3))->count();
            $activeSubscription = Tenant::where('data->expiry_date', '>=', Carbon::now()->format('Y-m-d'))->count();
            $monthlyRevenue = Tenant::where('data->subscription_start_date', '>=', Carbon::now()->startOfMonth())->sum('data->payment_amount');
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
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // public function usageMonitoring(Request $request)
    // {
    //     try {

    //         $perPage = $request->get('per_page', 10);

    //         $tenants = Tenant::paginate($perPage);

    //         $totalAPICalls = 0;
    //         $companyList = [];

    //         foreach ($tenants as $tenant) {

    //             $apiCallsToday = 0;
    //             $mapRequests = 0;
    //             $voipMinutes = 0;
    //             $dispatchersUsed = 0;

    //             $tenant->run(function () use (&$apiCallsToday, &$mapRequests, &$voipMinutes, &$dispatchersUsed) {

    //                 if (\Schema::hasTable('bookings')) {
    //                     $apiCallsToday = \DB::table('bookings')
    //                         ->whereDate('created_at', Carbon::today())
    //                         ->count();

    //                     $mapRequests = $apiCallsToday;
    //                 }

    //                 if (\Schema::hasTable('calls')) {
    //                     $voipMinutes = \DB::table('calls')
    //                         ->whereDate('created_at', Carbon::today())
    //                         ->sum('duration');
    //                 }

    //                 if (\Schema::hasTable('dispatchers')) {
    //                     $dispatchersUsed = \DB::table('dispatchers')->count();
    //                 }
    //             });

    //             $totalAPICalls += $apiCallsToday;

    //             $companyList[] = [
    //                 'company_name' => $tenant->company_name,
    //                 'api_calls_today' => $apiCallsToday,
    //                 'map_request' => $mapRequests,
    //                 'voip_minutes' => $voipMinutes,
    //                 'dispatchers' => $dispatchersUsed . '/' . $tenant->dispatchers_allowed
    //             ];
    //         }

    //         return response()->json([
    //             'success' => 1,
    //             'data' => [
    //                 'totalCompanies' => $tenants->total(),
    //                 'totalAPICalls' => $totalAPICalls
    //             ],
    //             'company_list' => $companyList,
    //             'pagination' => [
    //                 'current_page' => $tenants->currentPage(),
    //                 'last_page' => $tenants->lastPage(),
    //                 'per_page' => $tenants->perPage(),
    //                 'total' => $tenants->total()
    //             ]
    //         ]);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'error' => 1,
    //             'message' => $e->getMessage()
    //         ], 500);
    //     }
    // }


    public function usageMonitoring(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $tenants = Tenant::paginate($perPage);

            $totalAPICalls = 0;
            $companyList = [];

            foreach ($tenants as $tenant) {

                $apiCallsToday = 0;
                $mapRequests = 0;
                $voipMinutes = 0;
                $totalDispatchers = 0;
                $lastLogin = null;

                $tenant->run(function () use (&$apiCallsToday, &$mapRequests, &$voipMinutes, &$totalDispatchers, &$lastLogin) {

                    if (\Schema::hasTable('bookings')) {
                        $apiCallsToday = \DB::table('bookings')
                            ->whereDate('created_at', Carbon::today())
                            ->count();
                        $mapRequests = $apiCallsToday;
                    }

                    if (\Schema::hasTable('calls')) {
                        $voipMinutes = \DB::table('calls')
                            ->whereDate('created_at', Carbon::today())
                            ->sum('duration');
                    }

                    if (\Schema::hasTable('dispatchers')) {
                        $totalDispatchers = \DB::table('dispatchers')->count();

                        $lastLoginRow = \DB::table('dispatchers')
                            ->whereNotNull('last_login')
                            ->orderBy('last_login', 'DESC')
                            ->value('last_login');

                        if ($lastLoginRow) {
                            $lastLogin = $lastLoginRow;
                        }
                    }

                    if (\Schema::hasTable('admins') && !$lastLogin) {
                        $adminLastLogin = \DB::table('admins')
                            ->whereNotNull('last_login')
                            ->orderBy('last_login', 'DESC')
                            ->value('last_login');

                        if ($adminLastLogin) {
                            $lastLogin = $adminLastLogin;
                        }
                    }
                });

                $totalAPICalls += $apiCallsToday;
                $dispatchersAllowed = $tenant->data['dispatchers_allowed'] ?? 0;
                $companyList[] = [
                    'company_name' => $tenant->company_name,
                    'api_calls_today' => $apiCallsToday,
                    'map_request' => $mapRequests,
                    'voip_minutes' => $voipMinutes,
                    'dispatchers' => $totalDispatchers . '/' . $dispatchersAllowed,
                    'total_dispatchers' => $totalDispatchers,
                    'allowed_dispatchers' => $dispatchersAllowed,
                    'last_login' => $lastLogin
                        ? Carbon::parse($lastLogin)->diffForHumans()
                        : 'Never',
                    'last_login_raw' => $lastLogin,        
                ];
            }

            return response()->json([
                'success' => 1,
                'data' => [
                    'totalCompanies' => $tenants->total(),
                    'totalAPICalls' => $totalAPICalls
                ],
                'company_list' => $companyList,
                'pagination' => [
                    'current_page' => $tenants->currentPage(),
                    'last_page' => $tenants->lastPage(),
                    'per_page' => $tenants->perPage(),
                    'total' => $tenants->total()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function getAPIKeys()
    {
        try {
            $settingKeys = Setting::orderBy("id", "DESC")->first();
            return response()->json([
                'success' => 1,
                'settingKeys' => $settingKeys
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function storeAPIKeys(Request $request)
    {
        try {
            $settingKeys = Setting::orderBy("id", "DESC")->first();
            if (!isset($settingKeys) || $settingKeys == NULL) {
                $settingKeys = new Setting;
            }
            $settingKeys->stripe_secret = $request->stripe_secret;
            $settingKeys->stripe_key = $request->stripe_key;
            $settingKeys->stripe_webhook_secret = $request->stripe_webhook_secret;
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
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function paymentReminderList()
    {
        try {
            $tenantList = Tenant::whereDate('data->expiry_date', '<', today())->get();

            return response()->json([
                'success' => 1,
                'list' => $tenantList
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function sendReminder(Request $request)
    {
        try {
            $request->validate([
                'client_id' => 'required',
                'title' => 'required',
                'description' => 'required'
            ]);

            $data = new Notification;
            $data->tenant_id = $request->client_id;
            $data->title = $request->title;
            $data->description = $request->description;
            $data->save();

            \Log::info("Enter to reminder");

            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/send-reminder', [
                        'clientId' => $request->client_id,
                        'title' => $request->title,
                        'description' => $request->description
                    ]);

            return response()->json([
                'success' => 1,
                'message' => 'Remider sent successfully'
            ]);
        } catch (\Exception $e) {
            \Log::info($e->getMessage());
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
