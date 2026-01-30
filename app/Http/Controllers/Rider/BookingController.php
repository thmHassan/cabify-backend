<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyBooking;
use App\Models\CompanyRating;
use App\Models\CompanyVehicleType;
use App\Models\CompanySetting;
use App\Models\CompanyBid;
use App\Models\CompanyPlot;
use App\Models\CompanyDispatchSystem;
use App\Jobs\AutoDispatchPlotJob;
use App\Jobs\SendBiddingFixedFareNotificationJob;
use App\Jobs\AutoDispatchNearestDriverJob;
use Illuminate\Support\Facades\Http;
use App\Services\AutoDispatchPlotSocketService;

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
                'booking_type' => 'required',
                'pickup_point' => 'required',
                'pickup_location' => 'required',
                'destination_point' => 'required',
                'destination_location' => 'required',
                'vehicle' => 'required',
                'offered_amount' => 'required',
                'recommended_amount' => 'required',
                'distance' => 'required',
                'payment_method' => 'required',
            ]);


            $distance = $request->distance;
            $newBooking = new CompanyBooking;
            $newBooking->booking_id = "RD". strtoupper(uniqid());
            $newBooking->pickup_time = 'asap';
            $newBooking->booking_date = date("Y-m-d");
            $newBooking->booking_type = $request->booking_type;
            $newBooking->pickup_point = $request->pickup_point;
            $newBooking->pickup_plot_id = $request->pickup_plot_id;
            $newBooking->destination_plot_id = $request->destination_plot_id;
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
            $newBooking->vehicle = $request->vehicle;
            $newBooking->booking_status = 'pending';
            $newBooking->distance = $distance;
            $newBooking->offered_amount = $request->offered_amount;
            $newBooking->recommended_amount = $request->recommended_amount;
            $newBooking->note = $request->note;
            $newBooking->payment_method = $request->payment_method;
            $newBooking->otp = rand(1000,9999);
            $newBooking->save();

            $dispatch_system = CompanyDispatchSystem::where("priority", "1")->get();
                
            if($dispatch_system->first()->dispatch_system == "auto_dispatch_plot_base"){
                AutoDispatchPlotJob::dispatch($newBooking->id, 0, $request->header('database'));
                // AutoDispatchPlotSocketService::dispatch($newBooking, 0);
            }
            elseif($dispatch_system->first()->dispatch_system == "bidding_fixed_fare_plot_base"){
                SendBiddingFixedFareNotificationJob::dispatch($newBooking->id, NULL, 0, $request->header('database'));
            }
            elseif($dispatch_system->first()->dispatch_system == "auto_dispatch_nearest_driver"){
                AutoDispatchNearestDriverJob::dispatch($newBooking->id, $request->header('database'), []);
            }
            elseif($dispatch_system->first()->dispatch_system == "bidding"){
                SendBiddingNotificationJob::dispatch($newBooking->id);
                // $companyDrivers = CompanyDriver::where('driving_status', 'idle')->pluck('id')->toArray(); 
                // Http::withHeaders([
                //     'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                // ])->post('https://backend.cabifyit.com:3001/send-new-ride', [
                //     'drivers' => $companyDrivers,
                //     'booking' => [
                //         'id' => $newBooking->id,
                //         'booking_id' => $newBooking->booking_id,
                //         'pickup_point' => $newBooking->pickup_point,
                //         'destination_point' => $newBooking->destination_point,
                //         'offered_amount' => $newBooking->offered_amount,
                //         'distance' => $newBooking->distance,
                //     ]
                // ]);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Booking created successfully',
                'newBooking' => $newBooking
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

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                ])->post(env('NODE_SOCKET_URL') . '/bid-accept', [
                    'driverId' => $bid->driver_id,
                    'booking' => [
                        'id' => $booking->id,
                        'booking_id' => $booking->booking_id,
                        'pickup_point' => $booking->pickup_point,
                        'destination_point' => $booking->destination_point,
                        'offered_amount' => $booking->offered_amount,
                        'distance' => $booking->distance,
                        'type' => 'auto_dispatch_plot'
                    ]
                ]);
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
            $currentBooking = CompanyBooking::where("user_id", auth('rider')->user()->id)
                        ->where(function($q){
                            $q->where("booking_status", 'arrived')
                              ->orWhere("booking_status", 'ongoing');
                        })->with(['driverDetail', 'vehicleDetail'])->first();

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

    public function cancelRide(Request $request){
        try{
            $request->validate([
                'booking_id' => 'required'
            ]);

            $booking = CompanyBooking::where("id", $request->booking_id)->first();

            if(isset($booking) && $booking != NULL){
                $booking->booking_status = "cancelled";
                $booking->save();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Ride cancelled succesfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function cancelConfirmRide(Request $request){
        try{
            $request->validate([
                'booking_id' => 'required',
                'cancel_reason' => 'required',
            ]);

            $booking = CompanyBooking::where("id", $request->booking_id)->first();

            if(isset($booking) && $booking != NULL){
                $booking->booking_status = "cancelled";
                $booking->cancel_reason = $request->cancel_reason;
                $booking->cancelled_by = 'user';
                $booking->save();

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                ])->post(env('NODE_SOCKET_URL') . '/change-driver-ride-status', [
                    'driverId' => $booking->driver,
                    'status' => "cancel_confirm_ride",
                    'booking' => [
                        'id' => $booking->id,
                        'booking_id' => $booking->booking_id,
                        'pickup_point' => $booking->pickup_point,
                        'destination_point' => $booking->destination_point,
                        'offered_amount' => $booking->offered_amount,
                        'distance' => $booking->distance,
                        'type' => 'auto_dispatch_plot'
                    ]
                ]);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Ride cancelled succesfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeBookingPaymentStatus(Request $request){
        try{
            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            $booking->payment_status = "completed";
            $booking->save();

            return response()->json([
                'success' => 1,
                'message' => 'Payment status marked as completed'
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
