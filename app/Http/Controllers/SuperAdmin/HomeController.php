<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
}
