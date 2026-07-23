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
use App\Models\CompanyToken;
use App\Models\CompanyNotification;
use App\Models\CompanySendNewRide;
use App\Services\FCMService;
use App\Models\CompanyDriver;
use App\Models\CompanyDispatchSystem;
use App\Jobs\AutoDispatchPlotJob;
use App\Jobs\SendBiddingFixedFareNotificationJob;
use App\Jobs\AutoDispatchNearestDriverJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use App\Services\AutoDispatchPlotSocketService;
use App\Models\TenantUser;
use App\Models\WalletTransaction;
use App\Services\BookingDispatchService;
use App\Services\BookingLocationResolver;
use App\Services\PickupPlotResolver;
use App\Services\PreBookingService;
use App\Services\SocketApiUrlResolver;
use App\Support\MapsApi;
use App\Support\VehicleDispatchFilter;
use App\Services\TenantMapProviderResolver;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function completedRide(Request $request){
        try{
            $query = CompanyBooking::where("booking_status", "completed")->where("user_id", auth('rider')->user()->id);
            if(isset($request->date) && $request->date != NULL){
                $query->whereDate("booking_date", $request->date);
            }
            $completedRides = $query->with(['userDetail', 'driverDetail','ratingDetail', 'waitingDetail'])->orderBy("booking_date", "DESC")->paginate(10);

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

    public function cancelledRide(Request $request){
        try{
            $query = CompanyBooking::where("booking_status", "cancelled")->where("user_id", auth('rider')->user()->id);
            if(isset($request->date) && $request->date != NULL){
                $query->whereDate("booking_date", $request->date);
            }
            $cancelledRide = $query->with(['userDetail', 'driverDetail','ratingDetail'])->orderBy("updated_at", "DESC")->paginate(10);

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
                'any_ride' => 'nullable|boolean',
                'anyRide' => 'nullable|boolean',
            ]);

            $isAnyRide = $request->boolean('any_ride')
                || $request->boolean('anyRide')
                || strtolower((string) $request->input('request_for_vehicle')) === 'no'
                || !$request->filled('vehicle_id');

            $selectedVehicle = null;
            if (!$isAnyRide) {
                if (!$request->filled('vehicle_id')) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'The vehicle id field is required for a specific ride.',
                    ], 422);
                }

                $selectedVehicle = CompanyVehicleType::find($request->vehicle_id);

                if (!$selectedVehicle) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Selected vehicle type is invalid.',
                    ], 422);
                }
            }

            $tenant = \DB::connection('central')->table('tenants')->where("id", $request->header('database'))->first();
            $tenantData = json_decode($tenant->data ?? '{}');
            $tenantMap = app(TenantMapProviderResolver::class)->resolve((string) $request->header('database'));
            $map_api = $tenantMap['routing_provider'];

            $data = \DB::connection('central')->table('settings')->orderBy("id", "DESC")->first();   
            $barikoi_key = $tenantMap['credentials']['barikoi']['api_key'] ?? null;
            $google_map_key = $tenantMap['credentials']['google']['server_key']
                ?? $tenantMap['credentials']['google']['browser_key']
                ?? null;

            if ($map_api === 'mapify') {
                throw new \Exception('Mapify routing is selected, but its routing adapter is not configured.');
            }

            if (MapsApi::isMapify($map_api) && empty($barikoi_key)) {
                if ($tenantMap['allow_platform_fallback'] && !empty($google_map_key)) {
                    $map_api = MapsApi::GOOGLE;
                } else {
                    throw new \Exception('Barikoi routing credentials are not configured for this company.');
                }
            }

            if (strtolower((string) $map_api) === 'both') {
                if (!empty($google_map_key)) {
                    $map_api = MapsApi::GOOGLE;
                } elseif (!empty($barikoi_key)) {
                    $map_api = MapsApi::MAPIFY;
                } else {
                    throw new \Exception('Neither Barikoi nor Google Maps API key is configured.');
                }
            }
            
            if (MapsApi::isMapify($map_api)) {  
                
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
                    $curlError = curl_error($ch);
                    curl_close($ch);
    
                    if ($curlError !== '') {
                        throw new \Exception('Barikoi route API error: ' . $curlError);
                    }

                    $data = json_decode((string) $response, true);
                    if (!is_array($data) || !isset($data['routes'][0]['distance'])) {
                        $providerMessage = $data['message'] ?? $data['error'] ?? null;
                        throw new \Exception(
                            'Unable to calculate route distance. Please check pickup, destination, and Barikoi API key.'
                            . ($providerMessage ? ' Provider response: ' . (is_string($providerMessage) ? $providerMessage : json_encode($providerMessage)) : '')
                        );
                    }

                    $route = $data['routes'][0];
                    $polyline = $route['geometry'] ?? null;
                    $distance = (float) $route['distance'];
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
                        $curlError = curl_error($ch);
                        curl_close($ch);
        
                        if ($curlError !== '') {
                            throw new \Exception('Barikoi route API error: ' . $curlError);
                        }

                        $data = json_decode((string) $response, true);
                        if (!is_array($data) || !isset($data['routes'][0]['distance'])) {
                            $providerMessage = $data['message'] ?? $data['error'] ?? null;
                            throw new \Exception(
                                'Unable to calculate route distance for via points. Please check locations and Barikoi API key.'
                                . ($providerMessage ? ' Provider response: ' . (is_string($providerMessage) ? $providerMessage : json_encode($providerMessage)) : '')
                            );
                        }

                        $route = $data['routes'][0];
                        $polyline = $route['geometry'] ?? null;
                        $api_distance = $route['distance'];
                        $distance += (float) $api_distance;
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

                    $element = $data['rows'][0]['elements'][0] ?? null;
                    if (($data['status'] ?? '') !== 'OK' || ($element['status'] ?? '') !== 'OK' || !isset($element['distance']['value'])) {
                        $status = $data['error_message'] ?? ($element['status'] ?? ($data['status'] ?? 'UNKNOWN'));
                        throw new \Exception('Unable to calculate route distance using Google Maps. ' . $status);
                    }

                    $distance = $element['distance']['value'];
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

                        $element = $data['rows'][0]['elements'][0] ?? null;
                        if (($data['status'] ?? '') !== 'OK' || ($element['status'] ?? '') !== 'OK' || !isset($element['distance']['value'])) {
                            $status = $data['error_message'] ?? ($element['status'] ?? ($data['status'] ?? 'UNKNOWN'));
                            throw new \Exception('Unable to calculate route distance for via points using Google Maps. ' . $status);
                        }

                        $cDistance = $element['distance']['value'];
                        $distance += (float) $cDistance;
                    }
                }
            }
            if(json_decode($tenant->data)->units == "miles"){
                $cdistance = ($distance / 1609.344);
            }
            else{
                $cdistance = ($distance / 1000);
            }
            $isReturnJourney = isset($request->journey_type) && $request->journey_type == "return";
            if($isReturnJourney){
                $distance = 2 * $distance;
            }
            
            $tenantData = json_decode($tenant->data ?? '{}');
            $distanceUnit = strtolower((string) ($tenantData->units ?? '')) === "miles" ? "miles" : "km";

            $baseResponse = [
                'success' => 1,
                'distance' => $distance,
                'distance_value' => round($distanceUnit === "miles" ? ($distance / 1609.344) : ($distance / 1000), 2),
                'distance_unit' => $distanceUnit,
            ];

            if($isAnyRide){
                $vehicleFares = CompanyVehicleType::orderBy('order_no')->orderBy('id')->get()->map(function ($vehicle) use ($cdistance, $isReturnJourney) {
                    $amount = $this->calculateVehicleFare($vehicle, $cdistance);

                    return [
                        'vehicle_id' => $vehicle->id,
                        'vehicle_type' => $vehicle->vehicle_type_name,
                        'vehicle_image' => $vehicle->vehicle_image,
                        'calculate_fare' => $isReturnJourney ? 2 * $amount : $amount,
                    ];
                })->values();

                return response()->json($baseResponse + [
                    'any_ride' => true,
                    'vehicle_fares' => $vehicleFares,
                ]);
            }

            $vehicle = $selectedVehicle;
            $amount = $this->calculateVehicleFare($vehicle, $cdistance);

            return response()->json($baseResponse + [
                'any_ride' => false,
                'vehicle_id' => $vehicle->id,
                'vehicle_type' => $vehicle->vehicle_type_name,
                'vehicle_image' => $vehicle->vehicle_image,
                'calculate_fare' => $isReturnJourney ? 2 * $amount : $amount,
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function calculateVehicleFare(CompanyVehicleType $vehicle, float $distance): float
    {
        if($vehicle->mileage_system == "fixed"){
            $firstDistance = 1;
            $secondDistance = $distance - $firstDistance;
            $amount = (float) $vehicle->first_mile_km
                + ($secondDistance * (float) $vehicle->second_mile_km);
        }
        else{
            $fromArray = $vehicle->from_array;
            $toArray = $vehicle->to_array;
            $priceArray = $vehicle->price_array;
            $amount = 0;
            $remainDistance = $distance;

            for($i = 0; $i < count($fromArray); $i++){
                if($distance > $toArray[$i] && $remainDistance != 0){
                    $tempDistance = (float) $toArray[$i] - (float) $fromArray[$i];
                    $amount += $tempDistance * (float) $priceArray[$i];
                    $remainDistance = $distance - (float) $toArray[$i];
                }
                else{
                    $amount += $remainDistance * (float) $priceArray[$i];
                    $remainDistance = 0;
                }
            }

            if($remainDistance != 0 && count($priceArray) > 0){
                $amount += $remainDistance * (float) $priceArray[count($priceArray) - 1];
            }
        }

        if($vehicle->base_fare_system_status == "yes"){
            if($distance <= $vehicle->base_fare_less_than_x_miles){
                $amount += (float) $vehicle->base_fare_less_than_x_price;
            }
            else if($vehicle->base_fare_from_x_miles <= $distance && $distance <= $vehicle->base_fare_to_x_miles){
                $amount += (float) $vehicle->base_fare_from_to_price;
            }
            else if($distance >= $vehicle->base_fare_greater_than_x_miles){
                $amount += (float) $vehicle->base_fare_greater_than_x_price;
            }
        }

        return $amount;
    }

    public function getPlot(Request $request){
        try{
            $request->validate([
                'latitude' => 'required',
                'longitude' => 'required',
            ]);

            $lat = $request->latitude;
            $lng = $request->longitude;

            $plotId = app(PickupPlotResolver::class)
                ->resolveFromPickupPoint("{$lat},{$lng}");
            $matched = $plotId ? CompanyPlot::find($plotId) : null;

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
                'vehicle' => 'nullable',
                'offered_amount' => 'required',
                'recommended_amount' => 'required',
                'distance' => 'required',
                'payment_method' => 'required',
                'pickup_time_type' => 'nullable|in:asap,time',
                'booking_date' => 'nullable|date_format:Y-m-d',
                'pickup_time' => 'nullable|string',
                'pickup_at' => 'nullable|date',
                'pickup_timezone' => 'nullable|timezone',
                'dispatch_release_enabled' => 'nullable|in:yes,no,1,0,true,false',
                'auto_release' => 'nullable|in:yes,no,1,0,true,false',
                'dispatch_release_at' => 'nullable|date',
                'dispatch_release_mode' => 'nullable|in:auto_dispatch,bidding,auto_then_bidding,manual_review',
            ]);


            app(BookingLocationResolver::class)->resolveFromRequest($request);

            $distance = $request->distance;
            $preBookingService = app(PreBookingService::class);
            $pickupTimeType = $preBookingService->resolvePickupTimeType($request);
            $isScheduled = $pickupTimeType === 'time';
            $pickupTimezone = $preBookingService->companyTimezone();
            $pickupAt = null;

            if ($isScheduled) {
                if ($request->filled('pickup_at')) {
                    $pickupAt = \Carbon\Carbon::parse(
                        (string) $request->pickup_at,
                        $request->input('pickup_timezone', $pickupTimezone)
                    )->utc();
                } else {
                    $request->validate([
                        'booking_date' => 'required|date_format:Y-m-d',
                        'pickup_time' => 'required|date_format:H:i:s',
                    ]);

                    $pickupAt = $preBookingService->parseCompanyDateTimeToUtc(
                        $request->booking_date . ' ' . $request->pickup_time,
                        $pickupTimezone
                    );
                }

                if (!$pickupAt->isFuture()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'pickup_at' => 'The scheduled pickup time must be in the future.',
                    ]);
                }

                $localPickupAt = $pickupAt->copy()->setTimezone($pickupTimezone);
                $request->merge([
                    'booking_date' => $localPickupAt->toDateString(),
                    'pickup_time' => $localPickupAt->format('H:i:s'),
                    'pickup_timezone' => $pickupTimezone,
                ]);
            } else {
                $request->validate([
                    'pickup_at' => 'required|date',
                    'pickup_timezone' => 'required|timezone',
                ]);

                // Keep the instant supplied by the rider app and retain its timezone.
                $pickupTimezone = (string) $request->input('pickup_timezone');
                $pickupAt = Carbon::parse(
                    (string) $request->input('pickup_at'),
                    $pickupTimezone
                )->utc();
                $localPickupAt = $pickupAt->copy()->setTimezone($pickupTimezone);

                $request->merge([
                    'booking_date' => $localPickupAt->toDateString(),
                    'pickup_time' => $localPickupAt->format('H:i:s'),
                    'pickup_timezone' => $pickupTimezone,
                ]);
            }

            $newBooking = new CompanyBooking;
            $newBooking->booking_id = "RD". strtoupper(uniqid());
            $newBooking->pickup_time = (isset($request->pickup_time) && $request->pickup_time != NULL) ? $request->pickup_time : now()->format('H:i:s');
            $newBooking->booking_date = (isset($request->booking_date) && $request->booking_date != NULL) ? $request->booking_date : date("Y-m-d");
            // Keep booking creation compatible while tenant migrations roll out.
            // Assigning even null values would include missing columns in INSERT.
            if (Schema::connection('tenant')->hasColumns('bookings', ['pickup_at', 'pickup_timezone'])) {
                $newBooking->pickup_at = $pickupAt;
                $newBooking->pickup_timezone = $pickupTimezone;
            }
            $newBooking->pickup_time_type = $pickupTimeType;
            $newBooking->is_scheduled = $isScheduled;
            $newBooking->dispatch_released = false;
            $newBooking->booking_type = $request->booking_type;
            $newBooking->pickup_point = $request->pickup_point;
            $newBooking->pickup_plot_id = $request->pickup_plot_id ?? $request->pickup_point_id;
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
            $newBooking->vehicle = VehicleDispatchFilter::normalizeRequestedVehicle($request);
            $newBooking->booking_status = 'pending';
            $newBooking->distance = $distance;
            $newBooking->passenger = $request->passenger;
            $newBooking->luggage = $request->luggage;
            $newBooking->hand_luggage = $request->hand_luggage;
            $newBooking->offered_amount = $request->offered_amount;
            $newBooking->recommended_amount = $request->recommended_amount;
            $newBooking->booking_amount = $request->offered_amount;
            $newBooking->note = $request->note;
            $newBooking->payment_method = $request->payment_method;
            $newBooking->otp = rand(1000,9999);

            $bookingDispatchService = app(BookingDispatchService::class);
            $pickupPlotResolver = app(PickupPlotResolver::class);

            if ($bookingDispatchService->isPlotDispatchEnabled()) {
                $newBooking->driver = null;
                if (!$newBooking->pickup_plot_id) {
                    $newBooking->pickup_plot_id = $pickupPlotResolver->resolveFromPickupPoint($newBooking->pickup_point);
                }
            }

            $preBookingService->applyDispatchReleaseDefaults($newBooking, $request);
            $newBooking->dispatcher_action = $this->initialRiderDispatcherAction($newBooking, $preBookingService);
            $newBooking->save();

            $socketApiBaseUrl = SocketApiUrlResolver::resolve($request);
            if ($isScheduled) {
                $preBookingService->scheduleDispatchRelease(
                    $newBooking,
                    (string) $request->header('database')
                );
                $bookingDispatchService->notifyPreBookingCreated(
                    $newBooking,
                    (string) $request->header('database'),
                    $socketApiBaseUrl
                );
            } else {
                $bookingDispatchService->notifyImmediateBookingCreated(
                    $newBooking,
                    (string) $request->header('database'),
                    false,
                    $socketApiBaseUrl
                );
            }

            return response()->json([
                'success' => 1,
                'message' => 'Booking created successfully',
                'newBooking' => $newBooking,
                'is_scheduled' => $isScheduled,
                'pickup_time_type' => $pickupTimeType,
                'pre_booking' => $isScheduled && !$newBooking->dispatch_released,
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function initialRiderDispatcherAction(CompanyBooking $booking, PreBookingService $preBookingService): string
    {
        $prefix = 'Created by customer app.';

        if ($preBookingService->isScheduledBooking($booking)) {
            if ($booking->dispatch_release_at && $booking->dispatch_release_mode !== PreBookingService::RELEASE_MODE_MANUAL_REVIEW) {
                return $prefix . ' No driver selected - scheduled for auto release at '
                    . $preBookingService->formatStoredDateTimeForCompany($booking->dispatch_release_at, 'd M H:i')
                    . '.';
            }

            return $prefix . ' No driver selected - held for manual dispatch.';
        }

        return $prefix . ' No driver selected - dispatching now.';
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

                $driver = CompanyDriver::where("id", $bid->driver_id)->first();
                $companySetting = CompanySetting::orderBy("id", "DESC")->first();
                if ($companySetting->package_type == "ride_count_price") {
                    if($driver->ride_count_price <= 0){
                        return response()->json([
                            'error' => 1,
                            'message' => 'Driver does not have sufficient ride count to accept this ride'
                        ], 400);
                    }
                    $driver->ride_count_price -= 1;
                    $driver->save();
                }
                if($companySetting->package_type == "per_ride_commission_topup"){
                    $checkAmount = $companySetting->package_amount;
                    if($driver->wallet_balance < $checkAmount){
                        return response()->json([
                            'error' => 1,
                            'message' => 'Driver does not have sufficient balance to accept this ride'
                        ], 400);
                    }
                    $driver->wallet_balance -= $checkAmount;
                    $driver->save();

                    $wallet = new WalletTransaction;
                    $wallet->user_type = "driver";
                    $wallet->user_id = $driver->id;
                    $wallet->type = 'deduct';
                    $wallet->amount = $checkAmount;
                    $wallet->comment = "Per ride booking deduction";
                    $wallet->save();
                }

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                    'database' => $request->header('database'),
                ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/bid-accept', [
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

                $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

                if(isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){
                    $notification = new CompanyNotification;
                    $notification->user_type = "driver";
                    $notification->user_id = $bid->driver_id;
                    $notification->title = 'Accept Bid';
                    $notification->message = 'Your bid for ride has been accepted';
                    $notification->save();

                    $tokens = CompanyToken::where("user_id", $bid->driver_id)->where("user_type", "driver")->get();

                    if(isset($tokens) && $tokens != NULL){
                        foreach($tokens as $key => $token){
                            FCMService::sendToDevice(
                                $token->fcm_token,
                                'Accept Bid',
                                'Your bid for ride has been accepted',
                                [
                                    'booking_id' => $booking->id,
                                ]
                            );
                        }
                    }
                }

                $driver = CompanyDriver::where("id", $bid->driver_id)->first();
                $driver->driving_status = "busy";
                $driver->save();

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                    'database' => $request->header('database'),
                ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/on-job-driver', [
                    'clientId' => $request->header('database'),
                    'driverName' => $driver->name,
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
            $query = CompanyBooking::where("user_id", auth('rider')->user()->id);
            $this->applyRiderCurrentRideFilter($query);

            $currentBooking = $query
                ->with(['driverDetail', 'vehicleDetail', 'waitingDetail'])
                ->orderByRaw("CASE WHEN booking_status IN ('arrived', 'started', 'ongoing') THEN 0 ELSE 1 END")
                ->orderBy('id', 'DESC')
                ->first();

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

            $driversList = CompanySendNewRide::where("booking_id", $booking->id)->groupBy("driver_id")->pluck("driver_id");

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/change-cancel-ride', [
                'drivers' => $driversList,
                'status' => "cancel_ride",
                'cancelled_by' => 'user',
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

                $driver = $booking->driver
                    ? CompanyDriver::where("id", $booking->driver)->first()
                    : null;

                if ($driver) {
                    $driver->driving_status = "idle";
                    $driver->save();

                    Http::withHeaders([
                        'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                        'database' => $request->header('database'),
                    ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/change-driver-ride-status', [
                        'driverId' => $booking->driver,
                        'status' => "cancel_confirm_ride",
                        'booking' => [
                            'id' => $booking->id,
                            'booking_id' => $booking->booking_id,
                            'pickup_point' => $booking->pickup_point,
                            'destination_point' => $booking->destination_point,
                            'offered_amount' => $booking->offered_amount,
                            'distance' => $booking->distance,
                            'type' => 'auto_dispatch_plot',
                            'cancelled_by' => 'user'
                        ]
                    ]);
                }

                $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

                if($driver && isset($dataCheck) && $dataCheck->data['push_notification'] == "enable"){
                    $notification = new CompanyNotification;
                    $notification->user_type = "driver";
                    $notification->user_id = $booking->driver;
                    $notification->title = 'Cancel Ride';
                    $notification->message = 'Your ride has been cancelled by customer';
                    $notification->save();

                    $tokens = CompanyToken::where("user_id", $booking->driver)->where("user_type", "driver")->get();

                    if(isset($tokens) && $tokens != NULL){
                        foreach($tokens as $key => $token){
                            FCMService::sendToDevice(
                                $token->fcm_token,
                                'Cancel Ride',
                                'Your ride has been cancelled by customer',
                                [
                                    'booking_id' => $booking->id,
                                ]
                            );
                        }
                    }
                }
            }

            $driver = isset($driver)
                ? $driver
                : ($booking?->driver ? CompanyDriver::where("id", $booking->driver)->first() : null);
            $companySetting = CompanySetting::orderBy("id", "DESC")->first();
            if ($driver && $companySetting && $companySetting->package_type == "ride_count_price") {
                $driver->ride_count_price += 1;
                $driver->save();
            }
            if ($driver && $companySetting && $companySetting->package_type == "per_ride_commission_topup") {
                $checkAmount = $companySetting->package_amount;
                $driver->wallet_balance += $checkAmount;
                $driver->save();
            }
            if ($driver) {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                    'database' => $request->header('database'),
                ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/waiting-driver', [
                    'clientId' => $request->header('database'),
                    'driver_id' => $booking->driver,
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

    public function upcomingRide(Request $request){
        try{
            $query = CompanyBooking::where("booking_status", "pending")->where("user_id", auth('rider')->user()->id);
            $this->excludeAsapRides($query);
            if(isset($request->date) && $request->date != NULL){
                $query->whereDate("booking_date", $request->date);
            }
            else{
                $query->where("booking_date", ">=", date("Y-m-d"));
            }
            $pendingRides = $query->with(['userDetail', 'driverDetail','ratingDetail'])->orderBy("booking_date", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $pendingRides
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    private function applyRiderCurrentRideFilter($query): void
    {
        $query->where(function ($statusQuery) {
            $statusQuery->whereIn('booking_status', ['arrived', 'started', 'ongoing'])
                ->orWhere(function ($pendingAsapQuery) {
                    $pendingAsapQuery->where('booking_status', 'pending')
                        ->where(function ($asapQuery) {
                            $asapQuery->where('pickup_time_type', 'asap')
                                ->orWhereRaw('LOWER(TRIM(pickup_time)) = ?', ['asap']);
                        });
                });
        });
    }

    private function excludeAsapRides($query): void
    {
        $query->where(function ($typeQuery) {
            $typeQuery->whereNull('pickup_time_type')
                ->orWhere('pickup_time_type', '!=', 'asap');
        })->where(function ($timeQuery) {
            $timeQuery->whereNull('pickup_time')
                ->orWhereRaw('LOWER(TRIM(pickup_time)) != ?', ['asap']);
        });
    }

    public function rideDetail(Request $request){
        try{
            $rideDetail = CompanyBooking::where("id", $request->ride_id)->with(['driverDetail', 'ratingDetail'])->first();

            return response()->json([
                'success' => 1,
                'rideDetail' => $rideDetail
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
