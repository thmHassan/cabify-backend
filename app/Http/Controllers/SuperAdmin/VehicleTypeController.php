<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VehicleType;

class VehicleTypeController extends Controller
{
    public function createVehicleType(Request $request){
        try{
            $request->validate([
                'vehicle_type_name' => 'required|max:255',
                'vehicle_type_service' => 'required|max:255',
                'recommended_price' => 'required|max:255',
                'minimum_price' => 'required|max:255',
                'minimum_distance' => 'required|max:255',
                'base_fare_less_than_x_miles_status' => 'required',
                'base_fare_less_than_x_miles' => 'required_if:base_fare_less_than_x_miles_status,yes|max:255',
                'base_fare_less_than_x_price_status' => 'required',
                'base_fare_less_than_x_price' => 'required_if:base_fare_less_than_x_price_status,yes|max:255',
                'base_fare_from_to_miles_status' => 'required',
                'base_fare_from_x_miles' => 'required_if:base_fare_from_to_miles_status,yes|max:255',
                'base_fare_to_x_miles' => 'required_if:base_fare_from_to_miles_status,yes|max:255',
                'base_fare_from_to_price_status' => 'required',
                'base_fare_from_to_price' => 'required_if:base_fare_from_to_price_status,yes|max:255',
                'base_fare_greater_than_x_miles_status' => 'required',
                'base_fare_greater_than_x_miles' => 'required_if:base_fare_greater_than_x_miles_status,yes|max:255',
                'base_fare_greater_than_x_price_status' => 'required',
                'base_fare_greater_than_x_price' => 'required_if:base_fare_greater_than_x_price_status,yes|max:255',
                'first_mile_km' => 'required|max:255',
                'second_mile_km' => 'required|max:255',
            ]);
            
            $vehicleType = new VehicleType;
            $vehicleType->vehicle_type_name = $request->vehicle_type_name;
            $vehicleType->vehicle_type_service = $request->vehicle_type_service;
            $vehicleType->recommended_price = $request->recommended_price;
            $vehicleType->minimum_price = $request->minimum_price;
            $vehicleType->minimum_distance = $request->minimum_distance;
            $vehicleType->base_fare_less_than_x_miles_status = $request->base_fare_less_than_x_miles_status;
            $vehicleType->base_fare_less_than_x_miles = $request->base_fare_less_than_x_miles;
            $vehicleType->base_fare_less_than_x_price_status = $request->base_fare_less_than_x_price_status;
            $vehicleType->base_fare_less_than_x_price = $request->base_fare_less_than_x_price;
            $vehicleType->base_fare_from_to_miles_status = $request->base_fare_from_to_miles_status;
            $vehicleType->base_fare_from_x_miles = $request->base_fare_from_x_miles;
            $vehicleType->base_fare_to_x_miles = $request->base_fare_to_x_miles;
            $vehicleType->base_fare_from_to_price_status = $request->base_fare_from_to_price_status;
            $vehicleType->base_fare_from_to_price = $request->base_fare_from_to_price;
            $vehicleType->base_fare_greater_than_x_miles_status = $request->base_fare_greater_than_x_miles_status;
            $vehicleType->base_fare_greater_than_x_miles = $request->base_fare_greater_than_x_miles;
            $vehicleType->base_fare_greater_than_x_price_status = $request->base_fare_greater_than_x_price_status;
            $vehicleType->base_fare_greater_than_x_price = $request->base_fare_greater_than_x_price;
            $vehicleType->first_mile_km = $request->first_mile_km;
            $vehicleType->second_mile_km = $request->second_mile_km;
            $vehicleType->save();

            return response()->json([
                'success' => 1,
                'message' => 'Vehicle Type is saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editVehicleType(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'vehicle_type_name' => 'required|max:255',
                'vehicle_type_service' => 'required|max:255',
                'recommended_price' => 'required|max:255',
                'minimum_price' => 'required|max:255',
                'minimum_distance' => 'required|max:255',
                'base_fare_less_than_x_miles_status' => 'required',
                'base_fare_less_than_x_miles' => 'required_if:base_fare_less_than_x_miles_status,yes|max:255',
                'base_fare_less_than_x_price_status' => 'required',
                'base_fare_less_than_x_price' => 'required_if:base_fare_less_than_x_price_status,yes|max:255',
                'base_fare_from_to_miles_status' => 'required',
                'base_fare_from_x_miles' => 'required_if:base_fare_from_to_miles_status,yes|max:255',
                'base_fare_to_x_miles' => 'required_if:base_fare_from_to_miles_status,yes|max:255',
                'base_fare_from_to_price_status' => 'required',
                'base_fare_from_to_price' => 'required_if:base_fare_from_to_price_status,yes|max:255',
                'base_fare_greater_than_x_miles_status' => 'required',
                'base_fare_greater_than_x_miles' => 'required_if:base_fare_greater_than_x_miles_status,yes|max:255',
                'base_fare_greater_than_x_price_status' => 'required',
                'base_fare_greater_than_x_price' => 'required_if:base_fare_greater_than_x_price_status,yes|max:255',
                'first_mile_km' => 'required|max:255',
                'second_mile_km' => 'required|max:255',
            ]);
            
            $vehicleType = VehicleType::where("id", $request->id)->first();
            $vehicleType->vehicle_type_name = $request->vehicle_type_name;
            $vehicleType->vehicle_type_service = $request->vehicle_type_service;
            $vehicleType->recommended_price = $request->recommended_price;
            $vehicleType->minimum_price = $request->minimum_price;
            $vehicleType->minimum_distance = $request->minimum_distance;
            $vehicleType->base_fare_less_than_x_miles_status = $request->base_fare_less_than_x_miles_status;
            $vehicleType->base_fare_less_than_x_miles = $request->base_fare_less_than_x_miles;
            $vehicleType->base_fare_less_than_x_price_status = $request->base_fare_less_than_x_price_status;
            $vehicleType->base_fare_less_than_x_price = $request->base_fare_less_than_x_price;
            $vehicleType->base_fare_from_to_miles_status = $request->base_fare_from_to_miles_status;
            $vehicleType->base_fare_from_x_miles = $request->base_fare_from_x_miles;
            $vehicleType->base_fare_to_x_miles = $request->base_fare_to_x_miles;
            $vehicleType->base_fare_from_to_price_status = $request->base_fare_from_to_price_status;
            $vehicleType->base_fare_from_to_price = $request->base_fare_from_to_price;
            $vehicleType->base_fare_greater_than_x_miles_status = $request->base_fare_greater_than_x_miles_status;
            $vehicleType->base_fare_greater_than_x_miles = $request->base_fare_greater_than_x_miles;
            $vehicleType->base_fare_greater_than_x_price_status = $request->base_fare_greater_than_x_price_status;
            $vehicleType->base_fare_greater_than_x_price = $request->base_fare_greater_than_x_price;
            $vehicleType->first_mile_km = $request->first_mile_km;
            $vehicleType->second_mile_km = $request->second_mile_km;
            $vehicleType->save();

            return response()->json([
                'success' => 1,
                'message' => 'Vehicle Type is updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteVehicleType(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);

            $vehicleType = VehicleType::where("id", $request->id)->first();
            if(isset($vehicleType) && $vehicleType != NULL){
                $vehicleType->delete();
            }

            return response()->json([
                'error' => 1,
                'message' => 'Vehicle Type deleted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function vehicleTypeList(Request $request){
        try{
            $list = VehicleType::orderBy("id","DESC")->paginate(10);
            return response()->json([
                'success' => 1,
                'message' => 'List fetched successfully',
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
}
