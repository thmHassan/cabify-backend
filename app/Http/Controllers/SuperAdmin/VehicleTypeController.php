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
                'order_no' => 'required',
                'vehicle_type_service' => 'required|max:255',
                'minimum_price' => 'required|max:255',
                'minimum_distance' => 'required|max:255',
                'vehicle_image' => 'required',
                'backup_bid_vehicle_type' => 'required',
                'base_fare_system_status' => 'required',
                'base_fare_less_than_x_miles' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_less_than_x_price' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_from_x_miles' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_to_x_miles' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_from_to_price' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_greater_than_x_miles' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_greater_than_x_price' => 'required_if:base_fare_system,yes|max:255',
                'mileage_system' => 'required',
                'first_mile_km' => 'required_if:mileage_system,fixed|max:255',
                'second_mile_km' => 'required_if:mileage_system,fixed|max:255',
                'from_array' => 'required_if:mileage_system,dynamic',
                'to_array' => 'required_if:mileage_system,dynamic',
                'price_array' => 'required_if:mileage_system,dynamic',
            ]);
            
            $vehicleType = new VehicleType;
            $vehicleType->vehicle_type_name = $request->vehicle_type_name;
            $vehicleType->order_no = $request->order_no;
            $vehicleType->vehicle_type_service = $request->vehicle_type_service;
            $vehicleType->minimum_price = $request->minimum_price;
            $vehicleType->minimum_distance = $request->minimum_distance;
            if(isset($request->vehicle_image) && $request->vehicle_image != NULL){
                $file = $request->file('vehicle_image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('vehicle_image'), $filename);
                $vehicleType->vehicle_image = public_path('pictures').'/'.$filename;
            }
            $vehicleType->backup_bid_vehicle_type = implode(",", $request->backup_bid_vehicle_type);
            $vehicleType->base_fare_system_status = $request->base_fare_system_status;
            $vehicleType->base_fare_less_than_x_miles = $request->base_fare_less_than_x_miles;
            $vehicleType->base_fare_less_than_x_price = $request->base_fare_less_than_x_price;
            $vehicleType->base_fare_from_x_miles = $request->base_fare_from_x_miles;
            $vehicleType->base_fare_to_x_miles = $request->base_fare_to_x_miles;
            $vehicleType->base_fare_from_to_price = $request->base_fare_from_to_price;
            $vehicleType->base_fare_greater_than_x_miles = $request->base_fare_greater_than_x_miles;
            $vehicleType->base_fare_greater_than_x_price = $request->base_fare_greater_than_x_price;
            $vehicleType->mileage_system = $request->mileage_system;
            $vehicleType->first_mile_km = $request->first_mile_km;
            $vehicleType->second_mile_km = $request->second_mile_km;
            $vehicleType->from_array = implode(",", $request->from_array);
            $vehicleType->to_array = implode(",", $request->to_array);
            $vehicleType->price_array = implode(",", $request->price_array);
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
                'order_no' => 'required',
                'vehicle_type_service' => 'required|max:255',
                'minimum_price' => 'required|max:255',
                'minimum_distance' => 'required|max:255',
                'vehicle_image' => 'required',
                'backup_bid_vehicle_type' => 'required',
                'base_fare_system_status' => 'required',
                'base_fare_less_than_x_miles' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_less_than_x_price' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_from_x_miles' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_to_x_miles' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_from_to_price' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_greater_than_x_miles' => 'required_if:base_fare_system,yes|max:255',
                'base_fare_greater_than_x_price' => 'required_if:base_fare_system,yes|max:255',
                'mileage_system' => 'required',
                'first_mile_km' => 'required_if:mileage_system,fixed|max:255',
                'second_mile_km' => 'required_if:mileage_system,fixed|max:255',
                'from_array' => 'required_if:mileage_system,dynamic',
                'to_array' => 'required_if:mileage_system,dynamic',
                'price_array' => 'required_if:mileage_system,dynamic',
            ]);
            
            $vehicleType = VehicleType::where("id", $request->id)->first();
            $vehicleType->vehicle_type_name = $request->vehicle_type_name;
            $vehicleType->order_no = $request->order_no;
            $vehicleType->vehicle_type_service = $request->vehicle_type_service;
            $vehicleType->minimum_price = $request->minimum_price;
            $vehicleType->minimum_distance = $request->minimum_distance;
            if(isset($request->vehicle_image) && $request->vehicle_image != NULL){
                $file = $request->file('vehicle_image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('vehicle_image'), $filename);
                $vehicleType->vehicle_image = public_path('pictures').'/'.$filename;
            }
            $vehicleType->backup_bid_vehicle_type = implode(",", $request->backup_bid_vehicle_type);
            $vehicleType->base_fare_system_status = $request->base_fare_system_status;
            $vehicleType->base_fare_less_than_x_miles = $request->base_fare_less_than_x_miles;
            $vehicleType->base_fare_less_than_x_price = $request->base_fare_less_than_x_price;
            $vehicleType->base_fare_from_x_miles = $request->base_fare_from_x_miles;
            $vehicleType->base_fare_to_x_miles = $request->base_fare_to_x_miles;
            $vehicleType->base_fare_from_to_price = $request->base_fare_from_to_price;
            $vehicleType->base_fare_greater_than_x_miles = $request->base_fare_greater_than_x_miles;
            $vehicleType->base_fare_greater_than_x_price = $request->base_fare_greater_than_x_price;
            $vehicleType->mileage_system = $request->mileage_system;
            $vehicleType->first_mile_km = $request->first_mile_km;
            $vehicleType->second_mile_km = $request->second_mile_km;
            $vehicleType->from_array = implode(",", $request->from_array);
            $vehicleType->to_array = implode(",", $request->to_array);
            $vehicleType->price_array = implode(",", $request->price_array);
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

    public function getEditVehicleType(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);

            $vehicleType = VehicleType::where("id", $request->id)->first();

            return response()->json([
                'error' => 1,
                'vehicleType' => $vehicleType
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

    public function allVehicleTypeList(){
        try{
            $list = VehicleType::orderBy("id","DESC")->get();
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
