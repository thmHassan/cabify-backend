<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Hash;
use Illuminate\Validation\Rule;

class SubadminController extends Controller
{
    public function createSubadmin(Request $request){
        try{
            $request->validate([
                'name' => 'required|max:255',
                'email' => 'required|email|unique:users',
                'password' => 'required',
                'permissions' => 'required',
                'profile_picture' => 'required|image|mimes:jpg,jpeg,png,gif|max:2048', 
            ]);

            $subadmin = new User;
            $subadmin->name = $request->name;
            $subadmin->email = $request->email;
            $subadmin->password = Hash::make($request->password);
            $subadmin->permissions = json_encode($request->permissions);
            $subadmin->role = 'subadmin';
            $subadmin->save();

            if(isset($request->profile_picture) && $request->profile_picture != NULL){
                $file = $request->file('profile_picture');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('profile_pictures'), $filename);
                $subadmin->profile_picture = public_path('pictures').'/'.$filename;
                $subadmin->save();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Subadmin created successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function editSubadmin(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'name' => 'required|max:255',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users')->ignore($request->id),
                ],
                'permissions' => 'required',
                'profile_picture' => 'image|mimes:jpg,jpeg,png,gif|max:2048', 
            ]);

            $subadmin = User::where("id", $request->id)->first();
            $subadmin->name = $request->name;
            $subadmin->email = $request->email;
            $subadmin->password = (isset($request->password) && $request->password) ? Hash::make($request->password) : $subadmin->password;
            $subadmin->permissions = json_encode($request->permissions);
            $subadmin->save();

            if(isset($request->profile_picture) && $request->profile_picture != NULL){
                $file = $request->file('profile_picture');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('profile_pictures'), $filename);
                $subadmin->profile_picture = public_path('pictures').'/'.$filename;
                $subadmin->save();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Subadmin updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function subadminList(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $list = User::where('role','subadmin')->orderBy("id", "DESC");
            if(isset($request->search) && $request->search != NULL){
                $list->where(function($query) use ($request){
                    $query->where("name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("email", "LIKE" ,"%".$request->search."%");
                });
            }
            $data = $list->paginate($perPage);
            return response()->json([
                'success' => 1,
                'list' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getEditSubadmin(Request $request){
        try{
            $subadmin = User::where("id", $request->id)->first();
            return response()->json([
                'success' => 1,
                'subadmin' => $subadmin
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getSubadminPermission(Request $request){
        try{
            $user = $request->user();
            return response()->json([
                'success' => 1,
                'permissions' => $user->permissions
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
