<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyBooking;
use App\Models\CompanyRating;
use App\Models\CompanyVehicleType;
use App\Models\CompanySetting;
use App\Models\CompanyBid;

class BookingController extends Controller
{
    public function completedRide(){
        try{
            $query = CompanyBooking::where("booking_status", "completed")->where("user_id", auth('rider')->user()->id);
            if(isset($request->date) && $request->date != NULL){
                $query->whereDate("booking_date", $request->date);
            }
            $completedRides = $query->orderBy("booking_date", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $completedRides
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function cancelledRide(){
        try{
            $query = CompanyBooking::where("booking_status", "cancelled")->where("user_id", auth('rider')->user()->id);
            if(isset($request->date) && $request->date != NULL){
                $query->whereDate("booking_date", $request->date);
            }
            $cancelledRide = $query->orderBy("booking_date", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $cancelledRide
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function rateRide(Request $request){
        try{
            $request->validate([
                'booking_id' => 'required',
                'rating' => 'required'
            ]);

            $rating = new CompanyRating;
            $rating->booking_id = $request->booking_id;
            $rating->user_type = "user";
            $rating->rating = $request->rating;
            $rating->save();

            return response()->json([
                'success' => 1,
                'message' => 'Driver ratings given successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function calculateFare(Request $request){
        try{
            $request->validate([
                'pickup_point' => 'required',
                'destination_point' => 'required',
                'vehicle_id' => 'required'
            ]);

            $tenant = \DB::connection('central')->table('tenants')->where("id", $request->header('database'))->first();
            $map_api = json_decode($tenant->data)->maps_api;
            $map = json_decode($tenant->data)->map;

            if(isset($map) && $map == "enable"){
                if(isset($map_api) && $map_api != NULL){
                    $data = \DB::connection('central')->table('settings')->orderBy("id", "DESC")->first();   
                    $google_map_key = $data->google_map_key;
                    $barikoi_key = $data->barikoi_key;
                }
            }
            else{
                $data = CompanySetting::orderBy("id", "DESC")->first();
                $google_map_key = $data->google_api_keys;
                $barikoi_key = $data->barikoi_api_keys;
            }
            
            if(isset($map_api) && $map_api == "barikoi"){  
                
                if(!isset($request->via_point) || count($request->via_point) == 0){
                    $points = "{$request->pickup_point['longitude']},{$request->pickup_point['latitude']};{$request->destination_point['longitude']},{$request->destination_point['latitude']}";

                    $url = "https://barikoi.xyz/v2/api/route/{$points}?api_key={$barikoi_key}&geometries=geojson&profile=car";
    
                    $ch = curl_init();
    
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CUSTOMREQUEST => "GET",
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTPHEADER => [
                            "Accept: application/json"
                        ],
                    ]);
    
                    $response = curl_exec($ch);
    
                    if (curl_errno($ch)) {
                        echo 'cURL Error: ' . curl_error($ch);
                    } else {
                        $data = json_decode($response, true);
                        $route = $data['routes'][0];
                        $polyline = $route['geometry'];
                        $distance = $route['distance'];
                    }
                    curl_close($ch);
                }
                else{
                    $distance = 0;
                    for($i = 0; $i <= count($request->via_point); $i++){
                        if($i == 0){
                            $points = "{$request->pickup_point['longitude']},{$request->pickup_point['latitude']};{$request->via_point[$i]['longitude']},{$request->via_point[$i]['latitude']}";
                        }
                        else if($i == count($request->via_point)){
                            $points = "{$request->via_point[$i - 1]['longitude']},{$request->via_point[$i - 1]['latitude']};{$request->destination_point['longitude']},{$request->destination_point['latitude']}";
                        }
                        else{
                            $points = "{$request->via_point[$i - 1]['longitude']},{$request->via_point[$i - 1]['latitude']};{$request->via_point[$i]['longitude']},{$request->via_point[$i]['latitude']}";
                        }
    
                        $url = "https://barikoi.xyz/v2/api/route/{$points}?api_key={$barikoi_key}&geometries=geojson&profile=car";
        
                        $ch = curl_init();
        
                        curl_setopt_array($ch, [
                            CURLOPT_URL => $url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_CUSTOMREQUEST => "GET",
                            CURLOPT_TIMEOUT => 30,
                            CURLOPT_HTTPHEADER => [
                                "Accept: application/json"
                            ],
                        ]);
        
                        $response = curl_exec($ch);
        
                        if (curl_errno($ch)) {
                            echo 'cURL Error: ' . curl_error($ch);
                        } else {
                            $data = json_decode($response, true);
                            $route = $data['routes'][0];
                            $polyline = $route['geometry'];
                            $api_distance = $route['distance'];
                            $distance += (float) $api_distance;
                        }
                        curl_close($ch);
                    }
                }
            }
            else if(isset($map_api) && $map_api == "google"){  
                if(!isset($request->via_point) || count($request->via_point) == 0){
                    
                    $origin = "{$request->pickup_point['latitude']},{$request->pickup_point['longitude']}";
                    $destination = "{$request->destination_point['latitude']},{$request->destination_point['longitude']}";
                    
                    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$origin&destinations=$destination&mode=driving&units=metric&key=$google_map_key";

                    $response = file_get_contents($url);
                    $data = json_decode($response, true);

                    $distance = $data['rows'][0]['elements'][0]['distance']['value'];
                }
                else{
                    $distance = 0;
                    for($i = 0; $i <= count($request->via_point); $i++){
                        if($i == 0){
                            $origin = "{$request->pickup_point['latitude']},{$request->pickup_point['longitude']}";
                            $destination = "{$request->via_point[$i]['latitude']},{$request->via_point[$i]['longitude']}";
                        }
                        else if($i == count($request->via_point)){
                            $origin = "{$request->via_point[$i - 1]['latitude']},{$request->via_point[$i - 1]['longitude']}";
                            $destination = "{$request->destination_point['latitude']},{$request->destination_point['longitude']}";
                        }
                        else{
                            $origin = "{$request->via_point[$i - 1]['latitude']},{$request->via_point[$i - 1]['longitude']}";
                            $destination = "{$request->via_point[$i]['latitude']},{$request->via_point[$i]['longitude']}";
                        }

                        $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$origin&destinations=$destination&mode=driving&units=metric&key=$google_map_key";

                        $response = file_get_contents($url);
                        $data = json_decode($response, true);
                        $cDistance = $data['rows'][0]['elements'][0]['distance']['value'];
                        $distance += (float) $cDistance;
                    }
                }
            }
            else if(isset($map_api) && $map_api == "both"){  

            }

            $vehicle = CompanyVehicleType::where("id", $request->vehicle_id)->first();
            if(json_decode($tenant->data)->units == "miles"){
                $cdistance = ($distance / 1609.344);
            }
            else{
                $cdistance = ($distance / 1000);
            }
            if($vehicle->mileage_system == "fixed"){
                $firstDistance = 1;
                $secondDistance = (float) $cdistance - (float) $firstDistance;
                $firstAmount = $vehicle->first_mile_km;
                $secondAmount = (float) $secondDistance * (float) $vehicle->second_mile_km;
                $amount = (float) $firstAmount + (float) $secondAmount;
            }   
            else{
                $fromArray = $vehicle->from_array;
                $toArray = $vehicle->to_array;
                $priceArray = $vehicle->price_array;
                $amount = 0;
                $remainDistance = $cdistance;
                for($i = 0; $i < count($fromArray); $i++){
                    if($cdistance > $toArray[$i] && $remainDistance != 0){
                        $tempDistance = (float) $toArray[$i] - (float) $fromArray[$i];
                        $cAmount = (float) $tempDistance * (float) $priceArray[$i];
                        $amount += (float) $cAmount;
                        $remainDistance = (float) $cdistance - (float) $toArray[$i];
                    }
                    else{
                        $cAmount = (float) $remainDistance * (float) $priceArray[$i];
                        $amount += (float) $cAmount;
                        $remainDistance = 0;
                    }
                }
                if($remainDistance != 0){
                    $cAmount = (float) $remainDistance * (float) $priceArray[$i-1];
                    $amount += (float) $cAmount;
                }
            }         
            if($vehicle->base_fare_system_status == "yes"){
                if($cdistance <= $vehicle->base_fare_less_than_x_miles){
                    $amount = (float) $amount + (float) $vehicle->base_fare_less_than_x_price;
                }
                else if($vehicle->base_fare_from_x_miles <= $cdistance && $cdistance <= $vehicle->base_fare_to_x_miles){
                    $amount = (float) $amount + (float) $vehicle->base_fare_from_to_price;
                }
                else if($cdistance >= $vehicle->base_fare_greater_than_x_miles){
                    $amount = (float) $amount + (float) $vehicle->base_fare_greater_than_x_price;
                }
            }
            return response()->json([
                'success' => 1,
                'distance' => $distance,
                'calculate_fare' => $amount
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function createBooking(Request $request){
        try{
            $request->validate([
                'booking_type' => 'required',
                'pickup_point' => 'required',
                'pickup_location' => 'required',
                'destination_point' => 'required',
                'destination_location' => 'required',
                'vehicle' => 'required',
                'offered_amount' => 'required',
                'recommended_amount' => 'required',
                'distance' => 'required',
            ]);

            $distance = $request->distance;
            $newBooking = new CompanyBooking;
            $newBooking->booking_id = "RD". strtoupper(uniqid());
            $newBooking->pickup_time = 'asap';
            $newBooking->booking_date = date("Y-m-d");
            $newBooking->booking_type = $request->booking_type;
            $newBooking->pickup_point = $request->pickup_point;
            $newBooking->pickup_location = $request->pickup_location;
            $newBooking->destination_point = $request->destination_point;
            $newBooking->destination_location = $request->destination_location;
            $newBooking->via_point = json_encode($request->via_point);
            $newBooking->via_location = json_encode($request->via_location);
            $newBooking->user_id = auth("rider")->user()->id;
            $newBooking->name = auth("rider")->user()->name;
            $newBooking->email = auth("rider")->user()->email;
            $newBooking->phone_no = auth("rider")->user()->phone_no;
            $newBooking->tel_no = auth("rider")->user()->tel_no;
            $newBooking->journey_type = "one_way";
            // $newBooking->account = $request->account;
            $newBooking->vehicle = $request->vehicle;
            // $newBooking->booking_system = $request->booking_system;
            // $newBooking->parking_charge = $request->parking_charge;
            // $newBooking->waiting_charge = $request->waiting_charge;
            // $newBooking->ac_fares = $request->ac_fares;
            // $newBooking->return_ac_fares = $request->return_ac_fares;
            // $newBooking->ac_parking_charge = $request->ac_parking_charge;
            // $newBooking->ac_waiting_charge = $request->ac_waiting_charge;
            // $newBooking->extra_charge = $request->extra_charge;
            // $newBooking->toll = $request->toll;
            $newBooking->booking_status = 'pending';
            $newBooking->distance = $distance;
            $newBooking->offered_amount = $request->offered_amount;
            $newBooking->recommended_amount = $request->recommended_amount;
            $newBooking->save();

            return response()->json([
                'success' => 1,
                'message' => 'Booking created successfully',
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listBids(Request $request){
        try{
            $listBid = CompanyBid::where("booking_id", $request->booking_id)->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'bids' => $listBid
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeBidStatus(Request $request){
        try{
            $request->validate([
                'bid_id' => 'required',
                'status' => 'required',
            ]);

            $bid = CompanyBid::where("id", $request->bid_id)->first();
            $booking = CompanyBooking::where("id", $bid->booking_id)->first();

            $bid->status = $request->status;
            $bid->save();
            $message = "Bid rejected successfully";

            if($bid->status == "accepted"){
                $booking->booking_amount = $bid->amount;
                $booking->booking_status = "ongoing";
                $booking->driver = $bid->driver_id;
                $booking->save();
                $message = "Bid accepted successfully";
            }

            return response()->json([
                'success' => 1,
                'message' => $message
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function currentRide(Request $request){
        try{
            $currentBooking = CompanyBooking::where("user_id", auth("rider")->user()->id)->where("booking_status", "ongoing")->orderBy("id", "DESC")->first();

            return response()->json([
                'success' => 1,
                'currentBooking' => $currentBooking
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
