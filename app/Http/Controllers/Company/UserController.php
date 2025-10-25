<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyUser;
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
            $users = CompanyUser::orderBy("id", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'users' => $users
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
}
