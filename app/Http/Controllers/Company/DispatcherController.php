<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DispatcherController extends Controller
{
    public function createDispatcher(Request $request){
        try{
            $request->validate([
                'name' => 'required|max:255',
                'email' => 'required|email|unique:dispatchers,email',
                'password' => 'required|string|min:6',
                'phone_no' => 'required|max:255',
                'status' => 'required|max:255',
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
