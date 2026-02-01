<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Dispatcher;
use App\Models\CompanyDispatcherLog;
use Hash;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Notifications\CompanyNotification; 
use App\Models\CompanyNotification as CompanyNotificationTable;

class DispatcherController extends Controller
{
    public function createDispatcher(Request $request){
        try{
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

            $notification = new CompanyNotificationTable;
            $notification->user_type = "company";
            $notification->user_id = auth("tenant")->user()->id;
            $notification->title = "Dispatcher Added";
            $notification->message = 'You have added new dispatcher '.$dispatcher->name;
            $notification->save();

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

    public function dispatcherLogs(Request $request){
        try{
            $dispatcherLogs = CompanyDispatcherLog::where("dispatcher_id", $request->dispatcher_id)->whereDate("datetime", $request->date)->get();

            return response()->json([
                'success' => 1,
                'logs' => $dispatcherLogs
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
