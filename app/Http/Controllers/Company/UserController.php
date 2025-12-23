<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyUser;
use App\Models\CompanyBooking;
use Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function createUser(Request $request){
        try{
            $request->validate([
                'name' => 'required|max:255',
                'email' => 'required|email|unique:users,email',
                'phone_no' => 'required|max:255',
                'password' => 'required|string|min:6',
                'address' => 'required|max:255',
                'city' => 'required|max:255',
            ]);
            
            $newUser = new CompanyUser;
            $newUser->name = $request->name; 
            $newUser->email = $request->email; 
            $newUser->phone_no = $request->phone_no; 
            $newUser->password = Hash::make($request->password); 
            $newUser->address = $request->address; 
            $newUser->city = $request->city; 
            $newUser->dispatcher_id = $request->dispatcher_id; 
            $newUser->save();

            return response()->json([
                'success' => 1,
                'message' => 'User saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editUser(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'name' => 'required|max:255',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users')->ignore($request->id),
                ],
                'phone_no' => 'required|max:255',
                'address' => 'required|max:255',
                'city' => 'required|max:255',
            ]);
            
            $editUser = CompanyUser::where("id", $request->id)->first();
            $editUser->name = $request->name; 
            $editUser->email = $request->email; 
            $editUser->phone_no = $request->phone_no; 
            $editUser->address = $request->address; 
            $editUser->city = $request->city; 
            $editUser->dispatcher_id = $request->dispatcher_id; 
            $editUser->save();

            return response()->json([
                'success' => 1,
                'message' => 'User updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listUser(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $users = CompanyUser::orderBy("id", "DESC");
            if(isset($request->search) && $request->search != NULL){
                $users->where(function($query) use ($request){
                    $query->where("name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("email", "LIKE" ,"%".$request->search."%");
                });
            }
            if(isset($request->dispatcher_id) && $request->dispatcher_id != NULL){
                $users->where("dispatcher_id", $request->dispatcher_id);
            }
            $data = $users->paginate($perPage);

            return response()->json([
                'success' => 1,
                'users' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteUser(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);
            $user = CompanyUser::where("id", $request->id)->first();
            if(isset($user) && $user != NULL){
                $user->delete();
            }
            return response()->json([
                'success' => 1,
                'message' => 'User deleted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getEditUser(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);
            $user = CompanyUser::where("id", $request->id)->first();
            return response()->json([
                'success' => 1,
                'user' => $user
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeUserStatus(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);
            $user = CompanyUser::where("id", $request->id)->first();
            $user->status = $request->status;
            $user->save();

            return response()->json([
                'success' => 1,
                'message' => 'User status updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function rideHistory(Request $request){
        try{
            $data = CompanyBooking::where("user_id", $request->user_id)->with('driverDetail')->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'rideHistory' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function sendUserNotification(Request $request){
        try{
            $request->validate([
                'user_id' => 'required',
                'title' => 'required',
                'body' => 'required'
            ]);

            $user = CompanyUser::where("id", $request->user_id)->first();

            if(!isset($user->device_token) || $user->device_token == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User is not logged in to App'
                ], 400);
            }

            FCMService::sendToDevice(
                $user->device_token,
                $request->title,
                $request->body
            );

            return response()->json([
                'success' => 1,
                'message' => 'Notification sent successfully'
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
