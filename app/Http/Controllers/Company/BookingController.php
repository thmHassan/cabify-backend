<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyPlot;
use App\Models\CompanyBooking;
use App\Models\CompanyVehicleType;
use App\Models\CompanySetting;
use App\Models\CompanyDispatchSystem;
use Carbon\Carbon;
use App\Jobs\AutoDispatchPlotJob;
use App\Jobs\SendBiddingFixedFareNotificationJob;
use App\Jobs\AutoDispatchNearestDriverJob;

class BookingController extends Controller
{
    public function getPlot(Request $request){
        try{
            $request->validate([
                'latitude' => 'required',
                'longitude' => 'required',
            ]);

            $lat = $request->latitude;
            $lng = $request->longitude;

            $records = CompanyPlot::orderBy("id", "DESC")->get();
            $matched = null;
            foreach ($records as $rec) {
                $polygon = json_decode($rec->features, true);
                $array = json_decode($polygon['geometry']['coordinates'], true)[0];
                if ($this->pointInPolygon($lat, $lng, $array)) {
                    $matched = $rec;
                    break;
                }
            }
            return response()->json([
                'success' => 1,
                'found' => $matched ? 1 : 0,
                'record' => $matched
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function pointInPolygon($lat, $lng, $polygon)
    {
        if (count($polygon) == 2) {
            $lng1 = $polygon[0][0];
            $lat1 = $polygon[0][1];

            $lng2 = $polygon[1][0];
            $lat2 = $polygon[1][1];

            return (
                $lat >= min($lat1, $lat2) &&
                $lat <= max($lat1, $lat2) &&
                $lng >= min($lng1, $lng2) &&
                $lng <= max($lng1, $lng2)
            );
        }

        $inside = false;
        $x = $lng;
        $y = $lat;

        $numPoints = count($polygon);
        for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {

            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];

            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            $intersect = (($yi > $y) != ($yj > $y)) &&
                ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) $inside = !$inside;
        }
        return $inside;
    }

    public function createBooking(Request $request){
        try{
            $request->validate([
                'multi_booking' => 'required',
                'pickup_time' => 'required',
                'booking_date' => 'required',
                'booking_type' => 'required',
                'pickup_point' => 'required',
                'pickup_location' => 'required',
                'destination_point' => 'required',
                'destination_location' => 'required',
                'name' => 'required',
                'email' => 'required',
                'phone_no' => 'required',
                'journey_type' => 'required',
                'vehicle' => 'required',
                'passenger' => 'required',
                'booking_amount' => 'required',
                'payment_method' => 'required',
                'driver' => 'required_without:booking_system',
                'booking_system' => 'required_without:driver',
            ]);

            $distance = $request->distance;
            $nearBooking = 0;
            $alertMessage = NULL;

            if(isset($request->multi_booking) && $request->multi_booking == "yes"){
                $dayArray = array_map('trim', explode(',', $request->multi_days));
                $startDate = Carbon::parse($request->start_at);
                $endDate   = Carbon::parse($request->end_at);
                for ($date = $startDate; $date->lte($endDate); $date->addDay()) {
                    
                    if (in_array($date->format('D'), $dayArray)) {

                        if(isset($request->driver) && $request->driver != NULL && $alertMessage == NULL){
                            $bookingTime = Carbon::parse($request->pickup_time);
                            $startTime = $bookingTime->copy()->subHour()->format('H:i:s');
                            $endTime   = $bookingTime->copy()->addHour()->format('H:i:s');
                            $existingBooking = CompanyBooking::where("driver", $request->driver)->whereDate('booking_date', $date)->whereBetween('pickup_time', [$startTime, $endTime])->first();
    
                            if ($existingBooking && $alertMessage == NULL) {
                                $alertMessage = 'All bookings are done but Driver already has a booking within 1 hour of this time, Please confirm that';
                            }
                        }

                        $newBooking = new CompanyBooking;
                        $newBooking->booking_id = "RD". strtoupper(uniqid());
                        $newBooking->sub_company = $request->sub_company;
                        $newBooking->pickup_time = $request->pickup_time;
                        $newBooking->booking_date = $date;
                        $newBooking->booking_type = $request->booking_type;
                        $newBooking->pickup_point = $request->pickup_point;
                        $newBooking->pickup_location = $request->pickup_location;
                        $newBooking->destination_point = $request->destination_point;
                        $newBooking->destination_location = $request->destination_location;
                        $newBooking->via_point = json_encode($request->via_point);
                        $newBooking->via_location = json_encode($request->via_location);
                        $newBooking->user_id = $request->user_id;
                        $newBooking->name = $request->name;
                        $newBooking->email = $request->email;
                        $newBooking->phone_no = $request->phone_no;
                        $newBooking->tel_no = $request->tel_no;
                        $newBooking->journey_type = $request->journey_type;
                        $newBooking->account = $request->account;
                        $newBooking->vehicle = $request->vehicle;
                        $newBooking->driver = $request->driver;
                        $newBooking->passenger = $request->passenger;
                        $newBooking->luggage = $request->luggage;
                        $newBooking->hand_luggage = $request->hand_luggage;
                        $newBooking->special_request = $request->special_request;
                        $newBooking->payment_reference = $request->payment_reference;
                        $newBooking->booking_system = $request->booking_system;
                        $newBooking->parking_charge = $request->parking_charge;
                        $newBooking->waiting_charge = $request->waiting_charge;
                        $newBooking->ac_fares = $request->ac_fares;
                        $newBooking->return_ac_fares = $request->return_ac_fares;
                        $newBooking->ac_parking_charge = $request->ac_parking_charge;
                        $newBooking->ac_waiting_charge = $request->ac_waiting_charge;
                        $newBooking->extra_charge = $request->extra_charge;
                        $newBooking->toll = $request->toll;
                        $newBooking->booking_status = 'pending';
                        $newBooking->distance = $distance;
                        $newBooking->booking_amount = $request->booking_amount;
                        $newBooking->dispatcher_id = $request->dispatcher_id;
                        $newBooking->start_at = $request->start_at;
                        $newBooking->end_at = $request->end_at;
                        $newBooking->payment_method = $request->payment_method;
                        $newBooking->save();    
                    }
                }
            }
            else{
                if($request->pickup_time != 'asap' && $request->driver != NULL){
                    $date = $request->booking_date;
                    $bookingTime = Carbon::parse($request->pickup_time);
                    $startTime = $bookingTime->copy()->subHour()->format('H:i:s');
                    $endTime   = $bookingTime->copy()->addHour()->format('H:i:s');
                    $existingBooking = CompanyBooking::where("driver", $request->driver)->whereDate('booking_date', $date)->whereBetween('pickup_time', [$startTime, $endTime])->first();
                    if ($existingBooking && $alertMessage == NULL) {
                        $alertMessage = 'All bookings are done but Driver already has a booking within 1 hour of this time, Please confirm that';
                    }
                }
                else if($request->driver != NULL){
                    $date = $request->booking_date;
                    $existingBooking = CompanyBooking::where("driver", $request->driver)->whereDate('booking_date', $date)
                        ->where(function($q){
                            $q->where("booking_status", 'arrived')
                              ->orWhere("booking_status", 'started')
                              ->orWhere("booking_status", 'ongoing');
                        })
                        ->first();
                    if ($existingBooking && $alertMessage == NULL) {
                        $alertMessage = 'All bookings are done but Driver already has a booking within 1 hour of this time, Please confirm that';
                    }
                }

                $newBooking = new CompanyBooking;
                $newBooking->booking_id = "RD". strtoupper(uniqid());
                $newBooking->sub_company = $request->sub_company;
                $newBooking->multi_booking = $request->multi_booking;
                $newBooking->multi_days = $request->multi_days;
                $newBooking->pickup_time = $request->pickup_time;
                $newBooking->booking_date = $request->booking_date;
                $newBooking->booking_type = $request->booking_type;
                $newBooking->pickup_point = $request->pickup_point;
                $newBooking->pickup_location = $request->pickup_location;
                $newBooking->pickup_plot_id = $request->pickup_plot_id;
                $newBooking->destination_point = $request->destination_point;
                $newBooking->destination_location = $request->destination_location;
                $newBooking->destination_plot_id = $request->destination_plot_id;
                $newBooking->via_point = json_encode($request->via_point);
                $newBooking->via_location = json_encode($request->via_location);
                $newBooking->user_id = $request->user_id;
                $newBooking->name = $request->name;
                $newBooking->email = $request->email;
                $newBooking->phone_no = $request->phone_no;
                $newBooking->tel_no = $request->tel_no;
                $newBooking->journey_type = $request->journey_type;
                $newBooking->account = $request->account;
                $newBooking->vehicle = $request->vehicle;
                $newBooking->driver = $request->driver;
                $newBooking->passenger = $request->passenger;
                $newBooking->luggage = $request->luggage;
                $newBooking->hand_luggage = $request->hand_luggage;
                $newBooking->special_request = $request->special_request;
                $newBooking->payment_reference = $request->payment_reference;
                $newBooking->booking_system = $request->booking_system;
                $newBooking->parking_charge = $request->parking_charge;
                $newBooking->waiting_charge = $request->waiting_charge;
                $newBooking->ac_fares = $request->ac_fares;
                $newBooking->return_ac_fares = $request->return_ac_fares;
                $newBooking->ac_parking_charge = $request->ac_parking_charge;
                $newBooking->ac_waiting_charge = $request->ac_waiting_charge;
                $newBooking->extra_charge = $request->extra_charge;
                $newBooking->toll = $request->toll;
                $newBooking->booking_status = 'pending';
                $newBooking->distance = $distance;
                $newBooking->booking_amount = $request->booking_amount;
                $newBooking->dispatcher_id = $request->dispatcher_id;
                $newBooking->week = $request->week;
                $newBooking->start_at = $request->start_at;
                $newBooking->end_at = $request->end_at;
                $newBooking->payment_method = $request->payment_method;
                $newBooking->save();

                if(!isset($request->driver) || $request->driver == NULL){
                    $dispatch_system = CompanyDispatchSystem::where("priority", "1")->get();
                    
                    if($dispatch_system->first()->dispatch_system == "auto_dispatch_plot_base"){
                        AutoDispatchPlotJob::dispatch($newBooking->id, 0, $request->header('database'));
                    }
                    elseif($dispatch_system->first()->dispatch_system == "bidding_fixed_fare_plot_base"){
                        SendBiddingFixedFareNotificationJob::dispatch($newBooking->id, NULL, 0, $request->header('database'));
                    }
                    elseif($dispatch_system->first()->dispatch_system == "auto_dispatch_nearest_driver"){
                        AutoDispatchNearestDriverJob::dispatch($newBooking->id, $request->header('database'), []);
                    }
                }
            }

            return response()->json([
                'success' => 1,
                'message' => 'Booking created successfully',
                'alertMessage' => $alertMessage
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function calculateFares(Request $request){
        try{
            $request->validate([
                'pickup_point' => 'required',
                'destination_point' => 'required',
                'vehicle_id' => 'required'
            ]);

            $data = \DB::connection('central')->table('tenants')->where("id", auth('tenant')->user()->id)->first();
            $map_api = json_decode($data->data)->maps_api;
            $map = json_decode($data->data)->map;

            $data = \DB::connection('central')->table('settings')->orderBy("id", "DESC")->first();   
            $barikoi_key = $data->barikoi_key;

            if(isset($map) && $map == "enable"){
                if(isset($map_api) && $map_api != NULL){
                    $google_map_key = $data->google_map_key;
                }
            }
            else{
                $data = CompanySetting::orderBy("id", "DESC")->first();
                $google_map_key = $data->google_api_keys;
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
            if(auth('tenant')->user()->data['units'] == "miles"){
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

            if(isset($request->journey_type) && $request->journey_type == "return"){
                $distance = 2 * $distance;
                $amount = 2 * $amount;
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

    public function cancelledBooking(Request $request){
        try{
            $query = CompanyBooking::where("booking_status", 'cancelled')->with("driverDetail")->orderBy("id", "DESC");

            if(isset($request->search) && $request->search != NULL){
                $query->where(function($q) use ($request){
                    $q->where("booking_id", "LIKE", "%".$request->search."%")
                      ->orWhere("name", "LIKE", "%".$request->search."%");
                });
            }

            if(isset($request->dispatcher_id) && $request->dispatcher_id != NULL){
                $query->where("dispatcher_id", $request->dispatcher_id);
            }

            $bookings = $query->paginate(10);

            return response()->json([
                'success' => 1,
                'bookings' => $bookings
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function bookingList(Request $request){
        try{
            $query = CompanyBooking::orderBy("id", "DESC");

            if(isset($request->status) && $request->status != NULL){
                $query->where('booking_status', $request->status);
            }
            if(isset($request->date) && $request->date != NULL){
                $query->whereDate("created_at", $request->date);
            }
            if(isset($request->dispatcher_id) && $request->dispatcher_id != NULL){
                $query->where("dispatcher_id", $request->dispatcher_id);
            }
            $rides = $query->with('driverDetail')->paginate(10);

            return response()->json([
                'success' => 1,
                'rides' => $rides
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function rideDetail(Request $request){
        try{
            $rideDetail = CompanyBooking::where("id", $request->ride_id)->with(['driverDetail', 'vehicleDetail', 'subCompanyDetail', 'accountDetail'])->first();

            return response()->json([
                'success' => 1,
                'detail' => $rideDetail
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
