<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Dispatcher;
use Hash;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DispatcherController extends Controller
{
    public function createDispatcher(Request $request){
        try{
            // $database = request()->header('database');
            
            // if(!$database) {
            //     return response()->json(['error' => 1, 'message' => 'Database header missing'], 400);
            // }

            // Config::set('database.connections.tenant', [
            //     'driver' => 'mysql',
            //     'host' => env('DB_HOST', '127.0.0.1'),
            //     'port' => env('DB_PORT', '3306'),
            //     'database' => "tenant".$database,
            //     'username' => env('DB_USERNAME', 'root'),
            //     'password' => env('DB_PASSWORD', ''),
            //     'charset' => 'utf8mb4',
            //     'collation' => 'utf8mb4_unicode_ci',
            //     'prefix' => '',
            //     'strict' => false,
            // ]);

            // DB::purge('tenant');
            // DB::reconnect('tenant');
            // Config::set('database.default', 'tenant');

            $request->validate([
                'name' => 'required|max:255',
                'email' => 'required|email|unique:dispatcher,email',
                'password' => 'required|string|min:6',
                'phone_no' => 'required|max:255',
                'status' => 'required|max:255',
            ]);

            $dispatcher = new Dispatcher;
            $dispatcher->name = $request->name;
            $dispatcher->email = $request->email;
            $dispatcher->phone_no = $request->phone_no;
            $dispatcher->status = $request->status;
            $dispatcher->password = Hash::make($request->password);
            $dispatcher->save();

            $tenant = auth('tenant')->user();

            $tenant->notify(new CompanyNotification([
                'title' => 'Dispatcher Added',
                'message' => 'You have added new dispatcher '.$dispatcher->name
            ]));

            return response()->json([
                'success' => 1,
                'message' => 'Dispatcher saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editDispatcher(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'name' => 'required|max:255',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('dispatcher')->ignore($request->id),
                ],
                'password' => 'string|min:6',
                'phone_no' => 'required|max:255',
                'status' => 'required|max:255',
            ]);

            $dispatcher = Dispatcher::where("id", $request->id)->first();
            $dispatcher->name = $request->name;
            $dispatcher->email = $request->email;
            $dispatcher->phone_no = $request->phone_no;
            $dispatcher->status = $request->status;
            $dispatcher->password = (isset($request->password) && $request->password != NULL) ? Hash::make($request->password) : $dispatcher->password;
            $dispatcher->save();

            return response()->json([
                'success' => 1,
                'message' => 'Dispatcher updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getEditDispatcher(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);

            $dispatcher = Dispatcher::where("id", $request->id)->first();

            return response()->json([
                'success' => 1,
                'dispatcher' => $dispatcher
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listDispatcher(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $dispatchers = Dispatcher::orderBy("id", "DESC");
            if(isset($request->search) && $request->search != NULL){
                $dispatchers->where(function($query) use ($request){
                    $query->where("name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("email", "LIKE" ,"%".$request->search."%");
                });
            }
            $data = $dispatchers->paginate($perPage);

            return response()->json([
                'success' => 1,
                'dispatchers' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function dispatcherCards(){
        try{
            $totalDispatcher = Dispatcher::count();
            $activeDispatcher = Dispatcher::where('status', 'active')->count();
            $ridesDispatchToday = 19;

            return response()->json([
                'success' => 1,
                'data' => [
                    'totalDispatcher' => $totalDispatcher,
                    'activeDispatcher' => $activeDispatcher,
                    'ridesDispatchToday' => $ridesDispatchToday
                ]
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteDispatcher(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);

            $dispatcher = Dispatcher::where("id", $request->id)->first();
            if(isset($dispatcher) && $dispatcher != NULL){
                $dispatcher->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Dispatcher deleted successfully'
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
