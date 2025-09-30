<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    // POST /api/login
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'role' => 'required|string|in:superadmin,client,dispatcher,driver,passenger',
        ]);

        // Ensure user exists and has the role
        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            return response()->json(['error' => 1, 'message' => 'Invalid credentials'], 401);
        }

        if ($user->role !== $data['role']) {
            return response()->json(['error' => 1, 'message' => 'Role mismatch'], 403);
        }

        // Attempt to create token
        $credentials = ['email' => $data['email'], 'password' => $data['password']];
 
        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 1, 'message' => 'Invalid credentials'], 401);
        }

        return $this->respondWithToken($token, $user);
    }


    protected function respondWithToken($token, $user)
    {
        return response()->json([
            'success' => 1,    
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 200);
    }

    // logout
    public function logout()
    {
        auth('api')->logout();
        return response()->json(['message' => 'Successfully logged out']);
    }
}
