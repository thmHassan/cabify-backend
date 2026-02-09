<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDriver;
use Illuminate\Validation\Rule;
use App\Models\DriverDocument;
use App\Models\CompanyBooking;
use App\Models\CompanyNotification;
use App\Models\CompanySetting;
use App\Models\Setting;
use App\Services\FCMService;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class DriverController extends Controller
{
    public function createDriver(Request $request){
        try{
            $request->validate([
                'name' => 'required|max:255',
                'email' => 'required|email|unique:drivers,email',
                'phone_no' => 'required|max:255',
                'password' => 'required|string|min:6',
                'address' => 'required|max:255',
                'driver_license' => 'required|max:255',
                'assigned_vehicle' => 'required',
                'joined_date' => 'required',
                'sub_company' => 'required'
            ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            $countDriver = CompanyDriver::count();
            
            if($countDriver >= $dataCheck->data['drivers_allowed']){
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                ])->post(env('NODE_SOCKET_URL') . '/send-reminder', [
                    'clientId' => $request->header('database'),
                    'title' => "Driver Limit",
                    'description' => "You have reached your driver limits"
                ]);

                return response()->json([
                    'error' => 1,
                    'message' => 'You have already reached to driver limits'
                ]);
            }

            $driver = new CompanyDriver;
            $driver->name = $request->name;
            $driver->email = $request->email;
            $driver->phone_no = $request->phone_no;
            $driver->password = $request->password;
            $driver->address = $request->address;
            $driver->driver_license = $request->driver_license;
            $driver->assigned_vehicle = $request->assigned_vehicle;
            $driver->status = "pending";
            $driver->joined_date = $request->joined_date;
            $driver->sub_company = $request->sub_company;
            $driver->package_id = $request->package_id;
            $driver->dispatcher_id = $request->dispatcher_id;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Driver saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editDriver(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'name' => 'required|max:255',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('drivers')->ignore($request->id),
                ],
                'phone_no' => 'required|max:255',
                'address' => 'required|max:255',
                'driver_license' => 'required|max:255',
                'assigned_vehicle' => 'required',
                'joined_date' => 'required',
                'sub_company' => 'required'
            ]);

            $driver = CompanyDriver::where("id", $request->id)->first();
            $driver->name = $request->name;
            $driver->email = $request->email;
            $driver->phone_no = $request->phone_no;
            $driver->address = $request->address;
            $driver->driver_license = $request->driver_license;
            $driver->assigned_vehicle = $request->assigned_vehicle;
            $driver->joined_date = $request->joined_date;
            $driver->sub_company = $request->sub_company;
            $driver->package_id = $request->package_id;
            $driver->dispatcher_id = $request->dispatcher_id;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Driver updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getEditDriver(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);

            $driver = CompanyDriver::where("id", $request->id)->first();
            return response()->json([
                'success' => 1,
                'driver' => $driver
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listDriver(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $drivers = CompanyDriver::orderBy("id", "DESC");
            if(isset($request->status) && $request->status == "pending"){
                $drivers->where(function($query) use ($request){
                    $query->where("status", $request->status)->orWhereNULL("status");
                });
            }
            elseif(isset($request->status)){
                $drivers->where("status", $request->status);
            }
            elseif(isset($request->sub_company)){
                $drivers->where("sub_company", $request->sub_company);
            }
            if(isset($request->search) && $request->search != NULL){
                $drivers->where(function($query) use ($request){
                    $query->where("name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("email", "LIKE" ,"%".$request->search."%");
                });
            }
            // if(isset($request->dispatcher_id) && $request->dispatcher_id != NULL){
            //     $query->where("dispatcher_id", $request->dispatcher_id);
            // }
            $list = $drivers->paginate($perPage);
            return response()->json([
                'success' => 1,
                'list' => $list
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteDriver(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);

            $driver = CompanyDriver::where("id", $request->id)->first();
            if(isset($driver) && $driver != NULL){
                $driver->delete();
            }
            return response()->json([
                'success' => 1,
                'message' => 'Driver deleted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeDriverStatus(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'status' => 'required',
            ]);

            $driver = CompanyDriver::where("id", $request->id)->first();
            $driver->status = $request->status;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Driver status updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function addWalletBalance(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'amount' => 'required',
            ]);

            $driver = CompanyDriver::where("id", $request->id)->first();
            $driver->wallet_balance += $request->amount;
            $driver->save();

            $settingData = CompanySetting::orderBy("id", "DESC")->first();
            if($settingData->map_settings == "default"){
            
                $centralData = (new Setting)
                    ->setConnection('central')
                    ->orderBy("id", "DESC")
                    ->first();
                    
                $mail_server = $centralData->smtp_host;
                $mail_from = $centralData->smtp_from_address;
                $mail_user_name = $centralData->smtp_user_name;
                $mail_password = $centralData->smtp_password;
                $mail_port = 587;
            }
            else{
                $mail_server = $settingData->mail_server;
                $mail_from = $settingData->mail_from;
                $mail_user_name = $settingData->mail_user_name;
                $mail_password = $settingData->mail_password;
                $mail_port = $settingData->mail_port;
            }

             config([
                'mail.mailers.smtp.host' => $mail_server,
                'mail.mailers.smtp.port' => $mail_port,
                'mail.mailers.smtp.username' => $mail_user_name,
                'mail.mailers.smtp.password' => $mail_password,
                'mail.from.address' => $mail_from,
                'mail.from.name' => $mail_user_name,
            ]);

            Mail::send('emails.wallet-topup', [
                'name' => $driver->name ?? 'User',
                'amount' => $request->amount,
            ], function ($message) use ($driver) {
                $message->to($driver->email)
                        ->subject('Wallet Topup');
            });

            return response()->json([
                'success' => 1,
                'message' => 'Balance added successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function approvVehicleDetails(Request $request){
        try{
            $request->validate([
                'driver_id' => 'required'
            ]);

            $driver = CompanyDriver::where("id", $request->driver_id)->first();
            $driver->vehicle_change_request = 2;
            $driver->vehicle_service = $driver->change_vehicle_service;
            $driver->vehicle_type = $driver->change_vehicle_type;
            $driver->color = $driver->change_color;
            $driver->seats = $driver->change_seats;
            $driver->plate_no = $driver->change_plate_no;
            $driver->vehicle_registration_date = $driver->change_vehicle_registration_date;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Vehicle information approved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function rejectVehicleDetails(Request $request){
        try{
            $request->validate([
                'driver_id' => 'required'
            ]);

            $driver = CompanyDriver::where("id", $request->driver_id)->first();
            $driver->vehicle_change_request = 3;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Vehicle information rejected successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function driverDocumentList(Request $request){
        try{
            $documentList = DriverDocument::where("driver_id", $request->driver_id)->with("documentDetail")->orderBy("id","DESC")->get();

            return response()->json([
                'success' => 1,
                'documentList' => $documentList
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeStatusDocument(Request $request){
        try{
            if(isset($request->approve_all) && $request->approve_all == 1){
                $documentList = DriverDocument::where("driver_id", $request->driver_id)->where("status", "pending")->get();
                foreach($documentList as $document){
                    $document->status = 'verified';
                    $document->save();
                }
                $user = CompanyDriver::where("id", $request->driver_id)->first();

                $settingData = CompanySetting::orderBy("id", "DESC")->first();
                if($settingData->map_settings == "default"){
                
                    $centralData = (new Setting)
                        ->setConnection('central')
                        ->orderBy("id", "DESC")
                        ->first();
                        
                    $mail_server = $centralData->smtp_host;
                    $mail_from = $centralData->smtp_from_address;
                    $mail_user_name = $centralData->smtp_user_name;
                    $mail_password = $centralData->smtp_password;
                    $mail_port = 587;
                }
                else{
                    $mail_server = $settingData->mail_server;
                    $mail_from = $settingData->mail_from;
                    $mail_user_name = $settingData->mail_user_name;
                    $mail_password = $settingData->mail_password;
                    $mail_port = $settingData->mail_port;
                }

                config([
                    'mail.mailers.smtp.host' => $mail_server,
                    'mail.mailers.smtp.port' => $mail_port,
                    'mail.mailers.smtp.username' => $mail_user_name,
                    'mail.mailers.smtp.password' => $mail_password,
                    'mail.from.address' => $mail_from,
                    'mail.from.name' => $mail_user_name,
                ]);

                Mail::send('emails.document-status', [
                    'name' => $user->name ?? 'User',
                    'status' => "approved",
                ], function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Document Status Updated');
                });
            
            }
            else if(isset($request->reject_all) && $request->reject_all == 1){
                $documentList = DriverDocument::where("driver_id", $request->driver_id)->where("status", "pending")->get();
                foreach($documentList as $document){
                    $document->status = 'failed';
                    $document->save();
                }

                $user = CompanyDriver::where("id", $request->driver_id)->first();

                $settingData = CompanySetting::orderBy("id", "DESC")->first();
                if($settingData->map_settings == "default"){
                
                    $centralData = (new Setting)
                        ->setConnection('central')
                        ->orderBy("id", "DESC")
                        ->first();
                        
                    $mail_server = $centralData->smtp_host;
                    $mail_from = $centralData->smtp_from_address;
                    $mail_user_name = $centralData->smtp_user_name;
                    $mail_password = $centralData->smtp_password;
                    $mail_port = 587;
                }
                else{
                    $mail_server = $settingData->mail_server;
                    $mail_from = $settingData->mail_from;
                    $mail_user_name = $settingData->mail_user_name;
                    $mail_password = $settingData->mail_password;
                    $mail_port = $settingData->mail_port;
                }

                config([
                    'mail.mailers.smtp.host' => $mail_server,
                    'mail.mailers.smtp.port' => $mail_port,
                    'mail.mailers.smtp.username' => $mail_user_name,
                    'mail.mailers.smtp.password' => $mail_password,
                    'mail.from.address' => $mail_from,
                    'mail.from.name' => $mail_user_name,
                ]);

                Mail::send('emails.document-status', [
                    'name' => $user->name ?? 'User',
                    'status' => "rejected",
                ], function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Document Status Updated');
                });
            }
            else if(isset($request->driver_document_id) && $request->driver_document_id != NULL){
                $document = DriverDocument::where("id", $request->driver_document_id)->first();
                $document->status = $request->status;
                $document->save();

                $user = CompanyDriver::where("id", $request->driver_id)->first();

                $settingData = CompanySetting::orderBy("id", "DESC")->first();
                if($settingData->map_settings == "default"){
                
                    $centralData = (new Setting)
                        ->setConnection('central')
                        ->orderBy("id", "DESC")
                        ->first();
                        
                    $mail_server = $centralData->smtp_host;
                    $mail_from = $centralData->smtp_from_address;
                    $mail_user_name = $centralData->smtp_user_name;
                    $mail_password = $centralData->smtp_password;
                    $mail_port = 587;
                }
                else{
                    $mail_server = $settingData->mail_server;
                    $mail_from = $settingData->mail_from;
                    $mail_user_name = $settingData->mail_user_name;
                    $mail_password = $settingData->mail_password;
                    $mail_port = $settingData->mail_port;
                }

                config([
                    'mail.mailers.smtp.host' => $mail_server,
                    'mail.mailers.smtp.port' => $mail_port,
                    'mail.mailers.smtp.username' => $mail_user_name,
                    'mail.mailers.smtp.password' => $mail_password,
                    'mail.from.address' => $mail_from,
                    'mail.from.name' => $mail_user_name,
                ]);

                Mail::send('emails.document-status', [
                    'name' => $user->name ?? 'User',
                    'status' => $request->status,
                ], function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Document Status Updated');
                });
            }
            else if(isset($request->document_approved_office) && $request->document_approved_office == 1){
                $driver = CompanyDriver::where("id", $request->driver_id)->first();
                $driver->document_approved_office = 1;
                $driver->save();

                $settingData = CompanySetting::orderBy("id", "DESC")->first();
                if($settingData->map_settings == "default"){
                
                    $centralData = (new Setting)
                        ->setConnection('central')
                        ->orderBy("id", "DESC")
                        ->first();
                        
                    $mail_server = $centralData->smtp_host;
                    $mail_from = $centralData->smtp_from_address;
                    $mail_user_name = $centralData->smtp_user_name;
                    $mail_password = $centralData->smtp_password;
                    $mail_port = 587;
                }
                else{
                    $mail_server = $settingData->mail_server;
                    $mail_from = $settingData->mail_from;
                    $mail_user_name = $settingData->mail_user_name;
                    $mail_password = $settingData->mail_password;
                    $mail_port = $settingData->mail_port;
                }

                config([
                    'mail.mailers.smtp.host' => $mail_server,
                    'mail.mailers.smtp.port' => $mail_port,
                    'mail.mailers.smtp.username' => $mail_user_name,
                    'mail.mailers.smtp.password' => $mail_password,
                    'mail.from.address' => $mail_from,
                    'mail.from.name' => $mail_user_name,
                ]);

                Mail::send('emails.document-status', [
                    'name' => $user->name ?? 'User',
                    'status' => "approved",
                ], function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Document Status Updated');
                });
            }
            return response()->json([
                'success' => 1,
                'message' => 'Document status updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getDriverDocument(Request $request){
        try{
            $document = DriverDocument::where("id", $request->id)->first();

            return response()->json([
                'success' => 1,
                'document' => $document
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteDriverDocument(Request $request){
        try{
            $document = DriverDocument::where("id", $request->id)->first();

            if(isset($document) && $document != NULL){
                $document->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Document deleted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function driverRideHistory(Request $request){
        try{
            $rideHistory = CompanyBooking::where('driver', $request->driver_id)->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'rideHistory' => $rideHistory
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function sendDriverNotification(Request $request){
        try{
            $request->validate([
                'driver_id' => 'required',
                'title' => 'required',
                'body' => 'required'
            ]);

            $driver = CompanyDriver::where("id", $request->driver_id)->first();

            $tokens = CompanyToken::where("driver_id", $request->driver_id)->where("user_type", "driver")->get();

            if(isset($tokens) && $tokens != NULL){
                foreach($tokens as $key => $token){
                    FCMService::sendToDevice(
                        $token->device_token,
                        $request->title,
                        $request->body,
                    );
                }
            }

            $record = new CompanyNotification;
            $record->user_type = "driver";
            $record->user_id = $driver->id;
            $record->title = $title;
            $record->message = $body;
            $record->save();

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

    public function pendingDocumentList(Request $request){
        try{
            $data = DriverDocument::where("status", "pending")->with(['driverDetail', 'documentDetail'])->paginate(10);

            return response()->json([
                'success' => 1,
                "data" => $data
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
