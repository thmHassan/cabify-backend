<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDriver;
use Illuminate\Validation\Rule;
use App\Models\DriverDocument;
use App\Models\CompanyBooking;

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
            $drivers = CompanyDriver::where("status", $request->status)->orderBy("id", "DESC");
            if(isset($request->search) && $request->search != NULL){
                $drivers->where(function($query) use ($request){
                    $query->where("name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("email", "LIKE" ,"%".$request->search."%");
                });
            }
            if(isset($request->dispatcher_id) && $request->dispatcher_id != NULL){
                $query->where("dispatcher_id", $request->dispatcher_id);
            }
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
            }
            else if(isset($request->reject_all) && $request->reject_all == 1){
                $documentList = DriverDocument::where("driver_id", $request->driver_id)->where("status", "pending")->get();
                foreach($documentList as $document){
                    $document->status = 'failed';
                    $document->save();
                }
            }
            else if(isset($request->driver_document_id) && $request->driver_document_id != NULL){
                $document = DriverDocument::where("id", $request->driver_document_id)->first();
                $document->status = $request->status;
                $document->save();
            }
            else if(isset($request->document_approved_office) && $request->document_approved_office == 1){
                $driver = CompanyDriver::where("id", $request->driver_id)->first();
                $driver->document_approved_office = 1;
                $driver->save();
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
}
