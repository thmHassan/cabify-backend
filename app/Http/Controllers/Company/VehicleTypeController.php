<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyVehicleType;

class VehicleTypeController extends Controller
{
    public function createVehicleType(Request $request){
        try{
            $request->validate([
                'vehicle_type_name' => 'required|max:255',
                'order_no' => 'required',
                'vehicle_type_service' => 'required',
                'minimum_distance' => 'required',
                'vehicle_image' => 'required',
                // 'backup_bid_vehicle_type' => 'required',
                'base_fare_system_status' => 'required',
                'base_fare_less_than_x_miles' => 'required',
                'base_fare_less_than_x_price' => 'required',
                'base_fare_from_x_miles' => 'required',
                'base_fare_to_x_miles' => 'required',
                'base_fare_from_to_price' => 'required',
                'base_fare_greater_than_x_miles' => 'required',
                'base_fare_greater_than_x_price' => 'required',
                'mileage_system' => 'required',
                'first_mile_km' => 'required',
                'second_mile_km' => 'required',
                'from_array' => 'required',
                'to_array' => 'required',
                'price_array' => 'required',
                'attribute_array' => 'required',
            ]);

            $vehicleType = new CompanyVehicleType;
            $vehicleType->vehicle_type_name = $request->vehicle_type_name;
            $vehicleType->order_no = $request->order_no;
            $vehicleType->vehicle_type_service = $request->vehicle_type_service;
            $vehicleType->minimum_distance = $request->minimum_distance;
            if(isset($request->vehicle_image) && $request->vehicle_image != NULL){
                $file = $request->file('vehicle_image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('vehicle_image'), $filename);
                $vehicleType->vehicle_image = public_path('pictures').'/'.$filename;
            }
            $vehicleType->backup_bid_vehicle_type = implode(",",$request->backup_bid_vehicle_type);
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
            $vehicleType->from_array = implode(",",$request->from_array);
            $vehicleType->to_array = implode(",",$request->to_array);
            $vehicleType->price_array = implode(",",$request->price_array);
            $vehicleType->attributes = $request->attribute_array;
            $vehicleType->save();

            return response()->json([
                'success' => 1,
                'message' => 'Vehicle type saved successfully'
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
                'vehicle_type_service' => 'required',
                'minimum_distance' => 'required',
                // 'backup_bid_vehicle_type' => 'required',
                'base_fare_system_status' => 'required',
                'base_fare_less_than_x_miles' => 'required',
                'base_fare_less_than_x_price' => 'required',
                'base_fare_from_x_miles' => 'required',
                'base_fare_to_x_miles' => 'required',
                'base_fare_from_to_price' => 'required',
                'base_fare_greater_than_x_miles' => 'required',
                'base_fare_greater_than_x_price' => 'required',
                'mileage_system' => 'required',
                'first_mile_km' => 'required',
                'second_mile_km' => 'required',
                'from_array' => 'required',
                'to_array' => 'required',
                'price_array' => 'required',
                'attribute_array' => 'required',
            ]);

            $vehicleType = CompanyVehicleType::where("id", $request->id)->first();
            $vehicleType->vehicle_type_name = $request->vehicle_type_name;
            $vehicleType->order_no = $request->order_no;
            $vehicleType->vehicle_type_service = $request->vehicle_type_service;
            $vehicleType->minimum_distance = $request->minimum_distance;
            if(isset($request->vehicle_image) && $request->vehicle_image != NULL){
                $file = $request->file('vehicle_image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('vehicle_image'), $filename);
                $vehicleType->vehicle_image = public_path('pictures').'/'.$filename;
            }
            $vehicleType->backup_bid_vehicle_type = implode(",",$request->backup_bid_vehicle_type);
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
            $vehicleType->from_array = implode(",",$request->from_array);
            $vehicleType->to_array = implode(",",$request->to_array);
            $vehicleType->price_array = implode(",",$request->price_array);
            $vehicleType->attributes = $request->attribute_array;
            $vehicleType->save();

            return response()->json([
                'success' => 1,
                'message' => 'Vehicle type updated successfully'
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

            $vehicleType = CompanyVehicleType::where("id", $request->id)->first();
            return response()->json([
                'success' => 1,
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

    public function deleteVehicleType(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);

            $vehicleType = CompanyVehicleType::where("id", $request->id)->first();
            if(isset($vehicleType) && $vehicleType != NULL){
                $vehicleType->delete();
            }
            return response()->json([
                'success' => 1,
                'message' => "Vehicle Type deleted successfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listVehicleType(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $list = CompanyVehicleType::orderBy("id","DESC");
            if(isset($request->search) && $request->search != NULL){
                $list->where(function($query) use ($request){
                    $query->where("vehicle_type_name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("vehicle_type_service", "LIKE" ,"%".$request->search."%");
                });
            }
            $data = $list->paginate($perPage);

            return response()->json([
                'success' => 1,
                'list' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function allVehicleType(Request $request){
        try{
            $list = CompanyVehicleType::orderBy("id","DESC")->get();

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
}
