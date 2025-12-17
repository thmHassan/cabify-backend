<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDriver;
use App\Models\CompanyVehicleType;

class VehicleController extends Controller
{
    public function saveVehicleInformation(Request $request){
        try{
            $request->validate([
                'vehicle_service' => 'required',
                'vehicle_type' => 'required',
                'color' => 'required',
                'seats' => 'required',
                'plate_no' => 'required',
                'vehicle_registration_date' => 'required',
            ]);
            $driver = CompanyDriver::where("id", auth('driver')->user()->id)->first();
            $driver->vehicle_service = $request->vehicle_service;
            $driver->vehicle_type = $request->vehicle_type;
            $driver->color = $request->color;
            $driver->seats = $request->seats;
            $driver->plate_no = $request->plate_no;
            $driver->vehicle_registration_date = $request->vehicle_registration_date;
            $driver->vehicle_change_request = 1;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Vehicle information request sent successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getVehicleInformation(Request $request){
        try{
            $driver = CompanyDriver::where("id", auth('driver')->user()->id)->first();
            return response()->json([
                'success' => '1',
                'vehicle_info' => $driver
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function vehicleTypeList(Request $request){
        try{
            $vehicleTypeList = CompanyVehicleType::orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'list' => $vehicleTypeList
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
