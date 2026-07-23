<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyPlot;
use App\Models\CompanyBooking;
use App\Models\CompanyVehicleType;
use App\Models\CompanySetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use App\Models\CompanyUser;
use App\Models\WalletTransaction;
use App\Models\CompanyDriver;
use App\Models\DriverPackage;
use App\Models\Setting;
use App\Services\BookingDateClassificationService;
use App\Services\BookingDispatchService;
use App\Services\BookingLocationResolver;
use App\Services\BookingReminderService;
use App\Services\BookingUpdateService;
use App\Services\DuePreBookingReleaseService;
use App\Services\PickupPlotResolver;
use App\Services\PreBookingService;
use App\Services\SocketApiUrlResolver;
use App\Support\TenantRequestContext;
use App\Support\MapsApi;
use App\Support\VehicleDispatchFilter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingDateClassificationService $bookingDateClassification,
        private readonly BookingReminderService $bookingReminderService,
        private readonly PreBookingService $preBookingService,
        private readonly BookingDispatchService $bookingDispatchService,
        private readonly BookingUpdateService $bookingUpdateService,
        private readonly PickupPlotResolver $pickupPlotResolver,
        private readonly BookingLocationResolver $bookingLocationResolver,
        private readonly DuePreBookingReleaseService $duePreBookingReleaseService
    ) {
    }

    public function getPlot(Request $request)
    {
        try {
            $request->validate([
                'latitude' => 'required',
                'longitude' => 'required',
            ]);

            $lat = (float) $request->latitude;
            $lng = (float) $request->longitude;

            $records = CompanyPlot::orderBy("id", "DESC")->get();
            $matched = null;
            foreach ($records as $rec) {
                $array = $this->extractPlotCoordinates($rec->features);
                if ($array && $this->pointInPolygon($lat, $lng, $array)) {
                    $matched = $rec;
                    break;
                }
            }
            return response()->json([
                'success' => 1,
                'found' => $matched ? 1 : 0,
                'record' => $matched
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function extractPlotCoordinates(mixed $features): ?array
    {
        if (!$features) {
            return null;
        }

        $decoded = is_string($features) ? json_decode($features, true) : $features;
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['features'][0]['geometry']) && is_array($decoded['features'][0]['geometry'])) {
            $decoded = $decoded['features'][0];
        }

        $geometry = $decoded['geometry'] ?? $decoded;
        $coordinates = $geometry['coordinates'] ?? null;

        if (is_string($coordinates)) {
            $coordinates = json_decode($coordinates, true);
        }

        if (!is_array($coordinates)) {
            return null;
        }

        if (is_array($coordinates[0][0][0] ?? null)) {
            return $coordinates[0][0];
        }

        return is_array($coordinates[0][0] ?? null) ? $coordinates[0] : $coordinates;
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

            if ($intersect)
                $inside = !$inside;
        }
        return $inside;
    }

    public function createBooking(Request $request)
    {
        try {
            $plotDispatchEnabled = $this->bookingDispatchService->isPlotDispatchEnabled();

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
                'phone_no' => 'required',
                'journey_type' => 'required',
                'vehicle' => 'required_if:request_for_vehicle,yes',
                'passenger' => 'required',
                'booking_amount' => 'required|numeric|gt:0',
                'payment_method' => 'required',
                'driver' => $plotDispatchEnabled ? 'nullable' : 'required_without:booking_system',
                'booking_system' => $plotDispatchEnabled ? 'nullable' : 'required_without:driver',
                'bidding_fallback' => 'nullable|in:yes,no,1,0,true,false',
                'pickup_time_type' => 'nullable|in:asap,time',
                'pickup_at' => 'nullable|date',
                'pickup_timezone' => 'nullable|timezone',
                'dispatch_release_enabled' => 'nullable|in:yes,no,1,0,true,false',
                'auto_release' => 'nullable|in:yes,no,1,0,true,false',
                'dispatch_release_at' => 'nullable|date',
                'dispatch_release_mode' => 'nullable|in:auto_dispatch,bidding,auto_then_bidding,manual_review',
            ]);

            $this->bookingReminderService->validateReminderRequest($request);

            $this->bookingLocationResolver->resolveFromRequest($request);

            $socketApiBaseUrl = SocketApiUrlResolver::resolve($request);
            $tenantDatabase = TenantRequestContext::databaseId($request) ?? (string) $request->header('database');

            $isScheduled = $this->preBookingService->isScheduledRequest($request);
            $pickupTimeType = $this->preBookingService->resolvePickupTimeType($request);

            $distance = $request->distance;
            $nearBooking = 0;
            $alertMessage = NULL;
            $createdBookingSummaries = [];

            if (!$isScheduled && isset($request->driver) && $request->driver != NULL){
                $driver = CompanyDriver::where("id", $request->driver)->first();
                $companySetting = CompanySetting::orderBy("id", "DESC")->first();
                if ($companySetting->package_type == "per_ride_commission_topup") {
                    $checkAmount = $companySetting->package_amount;
                    if ($checkAmount > $driver->wallet_balance) {
                        return response()->json([
                            'error' => 1,
                            'message' => 'Driver wallet balance is not sufficient'
                        ], 400);
                    }
                }
                if ($companySetting->package_type == "ride_count_price") {
                    if($driver->ride_count_price <= 0){
                        return response()->json([
                            'error' => 1,
                            'message' => 'Driver ride count is not sufficient'
                        ], 400);
                    }
                }
                if ($companySetting->package_type == "packages_topup") {
                    $package = DriverPackage::where("driver_id", $driver->id)
                        ->where("package_type", "packages_postpaid")
                        ->orderBy("id", "DESC")
                        ->first();
    
                    if (!isset($package) || (isset($package) && $package->expire_date < date("Y-m-d"))) {
                        return response()->json([
                            'error' => 1,
                            'message' => 'Driver wallet balance is not sufficient'
                        ], 400);
                    }
                }
            }

            if (isset($request->multi_booking) && $request->multi_booking == "yes") {
                $request->validate([
                    'start_at' => 'required',
                    'end_at' => 'required|after_or_equal:start_at',
                    'multi_days' => 'required',
                ]);

                $dayArray = $this->bookingDateClassification->normalizeMultiDays($request->multi_days);
                $occurrenceDates = $this->bookingDateClassification->generateOccurrenceDates(
                    $request->start_at,
                    $request->end_at,
                    $dayArray
                );

                if (empty($occurrenceDates)) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'No booking dates matched the selected weekdays within the date range.',
                    ], 422);
                }

                if (isset($request->driver) && $request->driver != NULL) {
                    $driver = CompanyDriver::where("id", $request->driver)->first();
                    $companySetting = CompanySetting::orderBy("id", "DESC")->first();
                }

                $multiDaysStored = $this->formatMultiDaysForStorage($request->multi_days);
                $createdBookings = [];

                foreach ($occurrenceDates as $occurrenceDate) {
                    if (isset($request->driver) && $request->driver != NULL && $alertMessage == NULL) {
                        $bookingTime = Carbon::parse($request->pickup_time);
                        $startTime = $bookingTime->copy()->subHour()->format('H:i:s');
                        $endTime = $bookingTime->copy()->addHour()->format('H:i:s');
                        $existingBooking = CompanyBooking::where("driver", $request->driver)
                            ->whereDate('booking_date', $occurrenceDate)
                            ->whereBetween('pickup_time', [$startTime, $endTime])
                            ->first();

                        if ($existingBooking && $alertMessage == NULL) {
                            $alertMessage = 'All bookings are done but Driver already has a booking within 1 hour of this time, Please confirm that';
                        }
                    }

                    $existUser = CompanyUser::where("phone_no", $request->phone_no)->first();
                    if (!isset($existUser) || $existUser == NULL) {
                        $existUser = new CompanyUser;
                        $existUser->name = $request->name;
                        $existUser->email = $request->email;
                        $existUser->phone_no = $request->phone_no;
                        $existUser->save();
                    }

                    $newBooking = $this->buildBookingFromRequest($request, $occurrenceDate, $existUser, $distance);
                    $newBooking->multi_booking = 'yes';
                    $newBooking->multi_days = $multiDaysStored;
                    $this->applyAutomaticDispatchBookingDefaults($newBooking);
                    $newBooking->save();

                    if (!$isScheduled && isset($request->driver) && $request->driver != NULL) {
                        $this->applyDriverBookingDeductions($driver, $companySetting);
                    }

                    $this->bookingReminderService->scheduleReminder(
                        $newBooking,
                        $tenantDatabase
                    );

                    if ($isScheduled) {
                        $this->preBookingService->scheduleDispatchRelease(
                            $newBooking,
                            $tenantDatabase
                        );
                    }

                    $createdBookings[] = $newBooking;
                }

                foreach ($createdBookings as $createdBooking) {
                    if ($isScheduled) {
                        $this->bookingDispatchService->releaseForDispatch(
                            $createdBooking,
                            $tenantDatabase,
                            $socketApiBaseUrl
                        );
                    } else {
                        $this->bookingDispatchService->notifyImmediateBookingCreated(
                            $createdBooking,
                            $tenantDatabase,
                            true,
                            $socketApiBaseUrl
                        );
                    }

                    $createdBooking->refresh();
                    $createdBookingSummaries[] = $this->formatCreatedBookingSummary($createdBooking);
                }
            } else {
                if ($request->pickup_time != 'asap' && $request->driver != NULL) {
                    $date = $request->booking_date;
                    $bookingTime = Carbon::parse($request->pickup_time);
                    $startTime = $bookingTime->copy()->subHour()->format('H:i:s');
                    $endTime = $bookingTime->copy()->addHour()->format('H:i:s');
                    $existingBooking = CompanyBooking::where("driver", $request->driver)->whereDate('booking_date', $date)->whereBetween('pickup_time', [$startTime, $endTime])->first();
                    if ($existingBooking && $alertMessage == NULL) {
                        $alertMessage = 'All bookings are done but Driver already has a booking within 1 hour of this time, Please confirm that';
                    }
                } else if ($request->driver != NULL) {
                    $date = $request->booking_date;
                    $existingBooking = CompanyBooking::where("driver", $request->driver)->whereDate('booking_date', $date)
                        ->where(function ($q) {
                            $q->where("booking_status", 'started')
                                ->orWhere("booking_status", 'arrived')
                                ->orWhere("booking_status", 'ongoing');
                        })
                        ->first();
                    if ($existingBooking && $alertMessage == NULL) {
                        $alertMessage = 'All bookings are done but Driver already has a booking within 1 hour of this time, Please confirm that';
                    }
                }

                $existUser = CompanyUser::where("phone_no", $request->phone_no)->first();
                if(!isset($existUser) || $existUser == NULL){
                    $existUser = new CompanyUser;
                    $existUser->name = $request->name;
                    $existUser->email = $request->email;
                    $existUser->phone_no = $request->phone_no;
                    $existUser->save();                        
                }

                if(isset($request->driver) && $request->driver != NULL){
                    $driver = CompanyDriver::where("id", $request->driver)->first();
                    $companySetting = CompanySetting::orderBy("id", "DESC")->first();
                }

                $newBooking = $this->buildBookingFromRequest($request, $request->booking_date, $existUser, $distance);
                $newBooking->multi_booking = $request->multi_booking;
                $newBooking->multi_days = $request->multi_days;
                $newBooking->week = $request->week;
                $this->applyAutomaticDispatchBookingDefaults($newBooking);
                $newBooking->save();

                if (!$isScheduled && isset($request->driver) && $request->driver != NULL){
                    $this->applyDriverBookingDeductions($driver, $companySetting);
                }

                $this->bookingReminderService->scheduleReminder(
                    $newBooking,
                    $tenantDatabase
                );

                if ($isScheduled) {
                    $this->preBookingService->scheduleDispatchRelease(
                        $newBooking,
                        $tenantDatabase
                    );
                    $this->bookingDispatchService->releaseForDispatch(
                        $newBooking,
                        $tenantDatabase,
                        $socketApiBaseUrl
                    );
                } else {
                    $this->bookingDispatchService->notifyImmediateBookingCreated(
                        $newBooking,
                        $tenantDatabase,
                        false,
                        $socketApiBaseUrl
                    );
                }

                $newBooking->refresh();
                $createdBookingSummaries[] = $this->formatCreatedBookingSummary($newBooking);
            }

            if ($isScheduled) {
                $this->releaseDuePreBookingsAfterCreate($request, $socketApiBaseUrl);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Booking created successfully',
                'alertMessage' => $alertMessage,
                'is_scheduled' => $isScheduled,
                'pickup_time_type' => $pickupTimeType,
                'pre_booking' => $isScheduled,
                'bookings' => $createdBookingSummaries,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editBooking(Request $request)
    {
        try {
            if (!$this->canEditBooking($request)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Unauthorized',
                ], 401);
            }

            if (!$request->filled('id') && $request->route('id')) {
                $request->merge(['id' => (int) $request->route('id')]);
            }

            $request->validate([
                'id' => 'required|integer',
                'multi_booking' => 'sometimes|required',
                'pickup_time' => 'sometimes|required',
                'booking_date' => 'sometimes|required|date',
                'booking_type' => 'sometimes|required',
                'pickup_point' => 'sometimes|required',
                'pickup_location' => 'sometimes|required',
                'destination_point' => 'sometimes|required',
                'destination_location' => 'sometimes|required',
                'name' => 'sometimes|required',
                'phone_no' => 'sometimes|required',
                'journey_type' => 'sometimes|required',
                'vehicle' => 'sometimes|required_if:request_for_vehicle,yes',
                'passenger' => 'sometimes|required',
                'booking_amount' => 'sometimes|required|numeric|gt:0',
                'payment_method' => 'sometimes|required',
                'pickup_time_type' => 'nullable|in:asap,time',
                'pickup_at' => 'nullable|date',
                'pickup_timezone' => 'nullable|timezone',
                'reminder_minutes' => 'nullable|integer|in:5,15,30,50',
                'dispatch_release_enabled' => 'nullable|in:yes,no,1,0,true,false',
                'auto_release' => 'nullable|in:yes,no,1,0,true,false',
                'dispatch_release_at' => 'nullable|date',
                'dispatch_release_mode' => 'nullable|in:auto_dispatch,bidding,auto_then_bidding,manual_review',
            ]);

            $this->bookingReminderService->validateReminderRequest($request);

            $this->bookingLocationResolver->resolveFromRequest($request);

            $booking = CompanyBooking::where('id', $request->id)->first();
            if (!$booking) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Booking not found',
                ], 404);
            }

            $isScheduled = $request->has('pickup_time_type')
                ? $this->preBookingService->isScheduledRequest($request)
                : $this->preBookingService->isScheduledBooking($booking);

            if (!$isScheduled && $request->filled('driver')) {
                $driver = CompanyDriver::where('id', $request->driver)->first();
                if (!$driver) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Driver not found',
                    ], 404);
                }

                $companySetting = CompanySetting::orderBy('id', 'DESC')->first();
                if ($companySetting->package_type == 'per_ride_commission_topup') {
                    if ($companySetting->package_amount > $driver->wallet_balance) {
                        return response()->json([
                            'error' => 1,
                            'message' => 'Driver wallet balance is not sufficient',
                        ], 400);
                    }
                }
                if ($companySetting->package_type == 'ride_count_price') {
                    if ($driver->ride_count_price <= 0) {
                        return response()->json([
                            'error' => 1,
                            'message' => 'Driver ride count is not sufficient',
                        ], 400);
                    }
                }
                if ($companySetting->package_type == 'packages_topup') {
                    $package = DriverPackage::where('driver_id', $driver->id)
                        ->where('package_type', 'packages_postpaid')
                        ->orderBy('id', 'DESC')
                        ->first();

                    if (!isset($package) || $package->expire_date < date('Y-m-d')) {
                        return response()->json([
                            'error' => 1,
                            'message' => 'Driver wallet balance is not sufficient',
                        ], 400);
                    }
                }
            }

            $tenantDatabase = (string) $request->header('database');
            $socketApiBaseUrl = SocketApiUrlResolver::resolve($request);

            $updatedBooking = $this->bookingUpdateService->update(
                $booking,
                $request,
                $tenantDatabase
            );

            $this->bookingDispatchService->notifyBookingUpdated($updatedBooking, $tenantDatabase, $socketApiBaseUrl);

            return response()->json([
                'success' => 1,
                'message' => 'Booking updated successfully',
                'booking' => $this->bookingUpdateService->formatBookingPayload($updatedBooking),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function canEditBooking(Request $request): bool
    {
        if ($request->bearerToken() === config('services.node_socket.internal_secret')) {
            return true;
        }

        $routeMiddleware = $request->route()?->gatherMiddleware() ?? [];
        if (in_array('auth.tenant.jwt', $routeMiddleware, true)) {
            return true;
        }

        return false;
    }

    public function getEditBooking(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required_without:ride_id|integer',
                'ride_id' => 'required_without:id|integer',
            ]);

            $bookingId = $request->input('id', $request->ride_id);

            $booking = CompanyBooking::where('id', $bookingId)
                ->with(['driverDetail', 'vehicleDetail', 'subCompanyDetail', 'accountDetail'])
                ->first();

            if (!$booking) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Booking not found',
                ], 404);
            }

            return response()->json([
                'success' => 1,
                'detail' => $booking,
                'booking' => $this->bookingUpdateService->formatBookingPayload($booking),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function calculateFares(Request $request)
    {
        try {
            $request->validate([
                'pickup_point' => 'required',
                'destination_point' => 'required',
                'vehicle_id' => 'required'
            ], [
                'vehicle_id.required' => 'Please select any vehicle type for fare calculation.',
            ]);

            if (isset(auth('tenant')->user()->id)) {
                $tenantRecord = \DB::connection('central')->table('tenants')->where("id", auth('tenant')->user()->id)->first();
            } else {
                $tenantRecord = \DB::connection('central')->table('tenants')->where("id", $request->header('database'))->first();
            }

            $tenantData = json_decode($tenantRecord->data ?? '{}');
            $map_api = $tenantData->maps_api ?? null;

            $barikoi_key = Setting::barikoiKey();
            $companySettings = CompanySetting::orderBy("id", "DESC")->first();
            $tenant_google_api_key = $tenantRecord->google_api_key ?? ($tenantData->google_api_key ?? null);
            $google_map_key = in_array(strtolower((string) $map_api), ['google', 'both'], true)
                ? (trim((string) ($companySettings?->google_api_keys ?? '')) ?: $tenant_google_api_key)
                : null;

            if (in_array($map_api, ['google', 'both'], true) && empty($google_map_key)) {
                throw new \Exception('Google Maps API key is not configured.');
            }

            if (MapsApi::isMapify($map_api) && empty($barikoi_key)) {
                throw new \Exception('Barikoi API key is not configured.');
            }

            if (MapsApi::isMapify($map_api)) {

                if (!isset($request->via_point) || count($request->via_point) == 0) {
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
                        throw new \Exception('Barikoi route API error: ' . curl_error($ch));
                    } else {
                        $data = json_decode($response, true);
                        if (empty($data['routes'][0]['distance'])) {
                            throw new \Exception('Unable to calculate route distance. Please check pickup, destination, and Barikoi API key.');
                        }
                        $route = $data['routes'][0];
                        $polyline = $route['geometry'];
                        $distance = $route['distance'];
                    }
                    curl_close($ch);
                } else {
                    $distance = 0;
                    for ($i = 0; $i <= count($request->via_point); $i++) {
                        if ($i == 0) {
                            $points = "{$request->pickup_point['longitude']},{$request->pickup_point['latitude']};{$request->via_point[$i]['longitude']},{$request->via_point[$i]['latitude']}";
                        } else if ($i == count($request->via_point)) {
                            $points = "{$request->via_point[$i - 1]['longitude']},{$request->via_point[$i - 1]['latitude']};{$request->destination_point['longitude']},{$request->destination_point['latitude']}";
                        } else {
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
                            throw new \Exception('Barikoi route API error: ' . curl_error($ch));
                        } else {
                            $data = json_decode($response, true);
                            if (empty($data['routes'][0]['distance'])) {
                                throw new \Exception('Unable to calculate route distance for via points. Please check locations and Barikoi API key.');
                            }
                            $route = $data['routes'][0];
                            $polyline = $route['geometry'];
                            $api_distance = $route['distance'];
                            $distance += (float) $api_distance;
                        }
                        curl_close($ch);
                    }
                }
            } else if (isset($map_api) && $map_api == "google") {
                if (!isset($request->via_point) || count($request->via_point) == 0) {

                    $origin = "{$request->pickup_point['latitude']},{$request->pickup_point['longitude']}";
                    $destination = "{$request->destination_point['latitude']},{$request->destination_point['longitude']}";

                    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$origin&destinations=$destination&mode=driving&units=metric&key=$google_map_key";

                    $response = file_get_contents($url);
                    $data = json_decode($response, true);
                    if (($data['status'] ?? '') !== 'OK') {
                        $status = $data['status'] ?? 'UNKNOWN';
                        $errorMessage = $data['error_message'] ?? '';
                        throw new \Exception("Unable to calculate route distance (Google: {$status}). {$errorMessage}");
                    }
                    $element = $data['rows'][0]['elements'][0] ?? null;
                    if (empty($element['distance']['value']) || ($element['status'] ?? '') !== 'OK') {
                        $status = $element['status'] ?? ($data['status'] ?? 'UNKNOWN');
                        throw new \Exception("Unable to calculate route distance (Google: {$status}). Please verify locations and API key.");
                    }
                    $distance = $element['distance']['value'];
                } else {
                    $distance = 0;
                    for ($i = 0; $i <= count($request->via_point); $i++) {
                        if ($i == 0) {
                            $origin = "{$request->pickup_point['latitude']},{$request->pickup_point['longitude']}";
                            $destination = "{$request->via_point[$i]['latitude']},{$request->via_point[$i]['longitude']}";
                        } else if ($i == count($request->via_point)) {
                            $origin = "{$request->via_point[$i - 1]['latitude']},{$request->via_point[$i - 1]['longitude']}";
                            $destination = "{$request->destination_point['latitude']},{$request->destination_point['longitude']}";
                        } else {
                            $origin = "{$request->via_point[$i - 1]['latitude']},{$request->via_point[$i - 1]['longitude']}";
                            $destination = "{$request->via_point[$i]['latitude']},{$request->via_point[$i]['longitude']}";
                        }

                        $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$origin&destinations=$destination&mode=driving&units=metric&key=$google_map_key";

                        $response = file_get_contents($url);
                        $data = json_decode($response, true);
                        if (($data['status'] ?? '') !== 'OK') {
                            $status = $data['status'] ?? 'UNKNOWN';
                            $errorMessage = $data['error_message'] ?? '';
                            throw new \Exception("Unable to calculate route distance (Google: {$status}). {$errorMessage}");
                        }
                        $element = $data['rows'][0]['elements'][0] ?? null;
                        if (empty($element['distance']['value']) || ($element['status'] ?? '') !== 'OK') {
                            $status = $element['status'] ?? ($data['status'] ?? 'UNKNOWN');
                            throw new \Exception("Unable to calculate route distance for via points (Google: {$status}).");
                        }
                        $cDistance = $element['distance']['value'];
                        $distance += (float) $cDistance;
                    }
                }
            } else if (isset($map_api) && $map_api == "both") {
                if (empty($google_map_key)) {
                    throw new \Exception('Google Maps API key is not configured.');
                }
                if (!isset($request->via_point) || count($request->via_point) == 0) {
                    $origin = "{$request->pickup_point['latitude']},{$request->pickup_point['longitude']}";
                    $destination = "{$request->destination_point['latitude']},{$request->destination_point['longitude']}";
                    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$origin&destinations=$destination&mode=driving&units=metric&key=$google_map_key";
                    $response = file_get_contents($url);
                    $data = json_decode($response, true);
                    if (($data['status'] ?? '') !== 'OK') {
                        $status = $data['status'] ?? 'UNKNOWN';
                        $errorMessage = $data['error_message'] ?? '';
                        throw new \Exception("Unable to calculate route distance (Google: {$status}). {$errorMessage}");
                    }
                    $element = $data['rows'][0]['elements'][0] ?? null;
                    if (empty($element['distance']['value']) || ($element['status'] ?? '') !== 'OK') {
                        $status = $element['status'] ?? ($data['status'] ?? 'UNKNOWN');
                        throw new \Exception("Unable to calculate route distance (Google: {$status}). Please verify locations and API key.");
                    }
                    $distance = $element['distance']['value'];
                }
            }

            if (!isset($distance)) {
                throw new \Exception('Distance could not be calculated. Please check map API configuration.');
            }

            $vehicle = CompanyVehicleType::where("id", $request->vehicle_id)->first();

            if (!$vehicle) {
                throw new \Exception('Vehicle type not found.');
            }

            if (isset(auth('tenant')->user()->id)) {
                $data = \DB::connection('central')->table('tenants')->where("id", auth('tenant')->user()->id)->first();
            } else {
                $data = \DB::connection('central')->table('tenants')->where("id", $request->header('database'))->first();
            }

            $data = json_decode($data->data);

            if ($data->units == "miles") {
                $cdistance = ($distance / 1609.344);
            } else {
                $cdistance = ($distance / 1000);
            }
            if ($vehicle->mileage_system == "fixed") {
                $firstDistance = 1;
                $secondDistance = (float) $cdistance - (float) $firstDistance;
                $firstAmount = $vehicle->first_mile_km;
                $secondAmount = (float) $secondDistance * (float) $vehicle->second_mile_km;
                $amount = (float) $firstAmount + (float) $secondAmount;
            } else {
                $fromArray = $vehicle->from_array;
                $toArray = $vehicle->to_array;
                $priceArray = $vehicle->price_array;
                if (empty($fromArray) || empty($toArray) || empty($priceArray)) {
                    throw new \Exception('Vehicle pricing tiers are not configured for this vehicle type.');
                }
                $amount = 0;
                $remainDistance = $cdistance;
                for ($i = 0; $i < count($fromArray); $i++) {
                    if ($cdistance > $toArray[$i] && $remainDistance != 0) {
                        $tempDistance = (float) $toArray[$i] - (float) $fromArray[$i];
                        $cAmount = (float) $tempDistance * (float) $priceArray[$i];
                        $amount += (float) $cAmount;
                        $remainDistance = (float) $cdistance - (float) $toArray[$i];
                    } else {
                        $cAmount = (float) $remainDistance * (float) $priceArray[$i];
                        $amount += (float) $cAmount;
                        $remainDistance = 0;
                    }
                }
                if ($remainDistance != 0 && $i > 0 && isset($priceArray[$i - 1])) {
                    $cAmount = (float) $remainDistance * (float) $priceArray[$i - 1];
                    $amount += (float) $cAmount;
                }
            }
            if ($vehicle->base_fare_system_status == "yes") {
                if ($cdistance <= $vehicle->base_fare_less_than_x_miles) {
                    $amount = (float) $amount + (float) $vehicle->base_fare_less_than_x_price;
                } else if ($vehicle->base_fare_from_x_miles <= $cdistance && $cdistance <= $vehicle->base_fare_to_x_miles) {
                    $amount = (float) $amount + (float) $vehicle->base_fare_from_to_price;
                } else if ($cdistance >= $vehicle->base_fare_greater_than_x_miles) {
                    $amount = (float) $amount + (float) $vehicle->base_fare_greater_than_x_price;
                }
            }

            if (isset($request->journey) && $request->journey == "return") {
                $distance = 2 * $distance;
                $amount = 2 * $amount;
            }

            $settings = CompanySetting::orderBy("id", "DESC")->first();
            if ($settings) {
                $settings->maps_api_count = ($settings->maps_api_count ?? 0) + 1;
                $settings->last_use_map_api = \Carbon\Carbon::now();
                $settings->save();
            }

            $distanceUnit = strtolower((string) ($data->units ?? '')) === "miles" ? "miles" : "km";

            return response()->json([
                'success' => 1,
                'distance' => $distance,
                'distance_value' => round($distanceUnit === "miles" ? ($distance / 1609.344) : ($distance / 1000), 2),
                'distance_unit' => $distanceUnit,
                'calculate_fare' => $amount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function cancelledBooking(Request $request)
    {
        try {
            $query = CompanyBooking::where("booking_status", 'cancelled')->with("driverDetail")->orderBy("id", "DESC");

            if (isset($request->search) && $request->search != NULL) {
                $query->where(function ($q) use ($request) {
                    $q->where("booking_id", "LIKE", "%" . $request->search . "%")
                        ->orWhere("name", "LIKE", "%" . $request->search . "%");
                });
            }

            if (isset($request->dispatcher_id) && $request->dispatcher_id != NULL) {
                $query->where("dispatcher_id", $request->dispatcher_id);
            }

            $bookings = $query->paginate(10);

            return response()->json([
                'success' => 1,
                'bookings' => $bookings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function bookingList(Request $request)
    {
        try {
            $this->releaseDuePreBookingsBeforeListing($request);

            $query = CompanyBooking::orderBy('booking_date', 'DESC')->orderBy('id', 'DESC');

            if ($request->filled('filter')) {
                $query = $this->bookingDateClassification->applyFilter($query, $request->filter);
            }

            if (isset($request->status) && $request->status != NULL) {
                $query->where('booking_status', $request->status);
            }
            if (isset($request->date) && $request->date != NULL) {
                $query->whereDate('booking_date', $request->date);
            }
            if (isset($request->dispatcher_id) && $request->dispatcher_id != NULL) {
                $query->where("dispatcher_id", $request->dispatcher_id);
            }

            $perPage = $request->limit ?? $request->perPage ?? 10;
            $rides = $query->with('driverDetail')->paginate($perPage);
            $this->bookingLocationResolver->resolveCollection($rides->items(), true);

            return response()->json([
                'success' => 1,
                'rides' => $rides,
                'data' => $rides->items(),
                'pagination' => [
                    'total' => $rides->total(),
                    'page' => $rides->currentPage(),
                    'limit' => $rides->perPage(),
                    'total_pages' => $rides->lastPage(),
                    'hasNext' => $rides->hasMorePages(),
                    'hasPrev' => $rides->currentPage() > 1,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function releaseDuePreBookingsBeforeListing(Request $request): void
    {
        $tenantDatabase = TenantRequestContext::databaseId($request);
        if (!$tenantDatabase) {
            return;
        }

        $cacheKey = 'due_pre_booking_release:list:' . sha1($tenantDatabase);
        if (!Cache::add($cacheKey, true, now()->addSeconds(20))) {
            return;
        }

        try {
            $this->duePreBookingReleaseService->releaseDueForCurrentTenant(
                $tenantDatabase,
                SocketApiUrlResolver::resolve($request),
                25
            );
        } catch (\Throwable $e) {
            Log::warning('Panel due pre-booking release sweep failed', [
                'tenant' => $tenantDatabase,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function releaseDuePreBookingsAfterCreate(Request $request, ?string $socketApiBaseUrl = null): void
    {
        $tenantDatabase = TenantRequestContext::databaseId($request);
        if (!$tenantDatabase) {
            return;
        }

        try {
            $this->duePreBookingReleaseService->releaseDueForCurrentTenant(
                $tenantDatabase,
                $socketApiBaseUrl ?: SocketApiUrlResolver::resolve($request),
                25
            );
        } catch (\Throwable $e) {
            Log::warning('Create booking due pre-booking release sweep failed', [
                'tenant' => $tenantDatabase,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function rideDetail(Request $request)
    {
        try {
            $rideDetail = CompanyBooking::where("id", $request->ride_id)->with(['driverDetail', 'vehicleDetail', 'subCompanyDetail', 'accountDetail'])->first();

            if ($rideDetail) {
                $this->bookingLocationResolver->resolveForBooking($rideDetail, true);
            }

            return response()->json([
                'success' => 1,
                'detail' => $rideDetail
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteBooking(Request $request)
    {
        try {
            $booking = CompanyBooking::find($request->id);
            if ($booking) {
                $booking->delete();
                return response()->json([
                    'success' => 1,
                    'message' => 'Booking deleted successfully'
                ]);
            } else {
                return response()->json([
                    'error' => 1,
                    'message' => 'Booking not found'
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function formatMultiDaysForStorage($multiDays): ?string
    {
        if (is_array($multiDays)) {
            return implode(',', $multiDays);
        }

        return $multiDays !== null ? (string) $multiDays : null;
    }

    private function resolveAccountFromRequest(Request $request): ?string
    {
        $account = $request->input('account') ?? $request->input('account_id');

        return filled($account) ? (string) $account : null;
    }

    private function buildBookingFromRequest(Request $request, $bookingDate, CompanyUser $existUser, $distance): CompanyBooking
    {
        $pickupTimeType = $this->preBookingService->resolvePickupTimeType($request);
        $isScheduled = $pickupTimeType === 'time';
        $pickupTimezone = $isScheduled
            ? (string) $request->input('pickup_timezone', $this->preBookingService->companyTimezone())
            : null;
        $pickupAt = null;

        if ($isScheduled) {
            $pickupAt = $this->preBookingService->parseCompanyDateTimeToUtc(
                Carbon::parse($bookingDate)->toDateString() . ' ' . $request->pickup_time,
                $pickupTimezone
            );
        }

        $newBooking = new CompanyBooking;
        $newBooking->booking_id = "RD" . strtoupper(uniqid());
        $newBooking->sub_company = $request->sub_company;
        $newBooking->pickup_time = $request->pickup_time;
        $newBooking->pickup_at = $pickupAt;
        $newBooking->pickup_timezone = $pickupTimezone;
        $newBooking->pickup_time_type = $pickupTimeType;
        $newBooking->is_scheduled = $isScheduled;
        $newBooking->dispatch_released = false;
        $newBooking->reminder_minutes = $this->bookingReminderService->resolveReminderMinutes($request);
        $newBooking->booking_date = Carbon::parse($bookingDate)->toDateString();
        $newBooking->booking_type = $request->booking_type;
        $newBooking->pickup_point = $request->pickup_point;
        $newBooking->pickup_location = $request->pickup_location;
        $newBooking->pickup_plot_id = $request->pickup_plot_id ?? $request->pickup_point_id;
        $newBooking->destination_point = $request->destination_point;
        $newBooking->destination_location = $request->destination_location;
        $newBooking->destination_plot_id = $request->destination_plot_id;
        $newBooking->via_point = json_encode($request->via_point);
        $newBooking->via_location = json_encode($request->via_location);
        $newBooking->user_id = $existUser->id;
        $newBooking->name = $request->name;
        $newBooking->email = $request->email;
        $newBooking->phone_no = $request->phone_no;
        $newBooking->tel_no = $request->tel_no;
        $newBooking->journey_type = $request->journey_type;
        $newBooking->account = $this->resolveAccountFromRequest($request);
        $newBooking->vehicle = VehicleDispatchFilter::normalizeRequestedVehicle($request);
        if ($isScheduled && $request->filled('driver')) {
            $newBooking->pending_driver_id = $request->driver;
            $newBooking->driver = null;
        } else {
            $newBooking->driver = $request->driver;
            $newBooking->pending_driver_id = null;
        }
        $newBooking->passenger = $request->passenger;
        $newBooking->luggage = $request->luggage;
        $newBooking->hand_luggage = $request->hand_luggage;
        $newBooking->special_request = $request->special_request;
        $newBooking->payment_reference = $request->payment_reference;
        $newBooking->booking_system = $request->booking_system;
        $newBooking->bidding_fallback = filter_var($request->input('bidding_fallback', false), FILTER_VALIDATE_BOOLEAN);
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
        $bookingAmount = (float) $request->booking_amount;
        $newBooking->booking_amount = $bookingAmount;
        $newBooking->recommended_amount = $bookingAmount;
        $newBooking->offered_amount = $bookingAmount;
        $newBooking->dispatcher_id = $request->dispatcher_id;
        $newBooking->week = $request->week;
        $newBooking->start_at = $request->start_at;
        $newBooking->end_at = $request->end_at;
        $newBooking->payment_method = $request->payment_method;
        $this->preBookingService->applyDispatchReleaseDefaults($newBooking, $request);
        $newBooking->dispatcher_action = $this->buildInitialDispatcherAction($newBooking, $request);

        return $newBooking;
    }

    private function buildInitialDispatcherAction(CompanyBooking $booking, Request $request): string
    {
        $dispatcherName = trim((string) $request->input('dispatcher_name', 'Dispatcher'));
        if ($dispatcherName === '') {
            $dispatcherName = 'Dispatcher';
        }

        $prefix = "Created by {$dispatcherName}.";

        if ($this->preBookingService->isScheduledBooking($booking)) {
            if ($booking->pending_driver_id) {
                $releaseText = $booking->dispatch_release_at
                    ? ' Driver selected - scheduled for release at ' . $this->preBookingService->formatStoredDateTimeForCompany($booking->dispatch_release_at, 'd M H:i') . '.'
                    : ' Driver selected - held until manual release.';

                return $prefix . $releaseText;
            }

            if ($booking->dispatch_release_at && $booking->dispatch_release_mode !== PreBookingService::RELEASE_MODE_MANUAL_REVIEW) {
                return $prefix . ' No driver selected - scheduled for auto release at '
                    . $this->preBookingService->formatStoredDateTimeForCompany($booking->dispatch_release_at, 'd M H:i')
                    . '.';
            }

            return $prefix . ' No driver selected - held for manual dispatch.';
        }

        if ($booking->driver) {
            return $prefix . ' Driver selected - dispatching now.';
        }

        if ($booking->booking_system === 'bidding') {
            return $prefix . ' No driver selected - released to bidding.';
        }

        if ($booking->booking_system === 'bidding_fixed_fare_plot_base') {
            return $prefix . ' No driver selected - released to fixed fare bidding.';
        }

        return $prefix . ' No driver selected - dispatching now.';
    }

    private function applyAutomaticDispatchBookingDefaults(CompanyBooking $booking): void
    {
        if (in_array($booking->booking_system, ['bidding', 'bidding_fixed_fare_plot_base'], true)) {
            return;
        }

        $plotDispatchEnabled = $this->bookingDispatchService->isPlotDispatchEnabled();
        $nearestDispatchEnabled = $this->bookingDispatchService->isNearestDriverDispatchEnabled();

        if (!$plotDispatchEnabled && !$nearestDispatchEnabled) {
            return;
        }

        if ($plotDispatchEnabled && !$booking->is_scheduled && !$booking->driver) {
            $booking->driver = null;
            $booking->pending_driver_id = null;
        }

        if ($plotDispatchEnabled && $this->bookingDispatchService->isFixedFarePlotDispatchEnabled() && !$booking->driver) {
            $booking->booking_system = 'bidding_fixed_fare_plot_base';
            $booking->bidding_fallback = true;
        }

        if (!$booking->pickup_plot_id) {
            $booking->pickup_plot_id = $this->pickupPlotResolver->resolveFromPickupPoint($booking->pickup_point);
        }
    }

    private function formatCreatedBookingSummary(CompanyBooking $booking): array
    {
        return [
            'id' => $booking->id,
            'booking_id' => $booking->booking_id,
            'is_scheduled' => (bool) $booking->is_scheduled,
            'pickup_time_type' => $booking->pickup_time_type,
            'pre_booking' => (bool) $booking->is_scheduled && !$booking->dispatch_released,
            'booking_date' => $booking->booking_date,
            'pickup_time' => $booking->pickup_time,
            'pickup_at' => $booking->pickup_at?->toIso8601String(),
            'pickup_at_local' => $booking->pickup_at_local,
            'pickup_timezone' => $booking->pickup_timezone,
            'booking_status' => $booking->booking_status,
            'booking_amount' => $booking->booking_amount,
            'recommended_amount' => $booking->recommended_amount,
            'offered_amount' => $booking->offered_amount,
            'payment_method' => $booking->payment_method,
            'passenger' => $booking->passenger,
            'phone_no' => $booking->phone_no,
            'pickup_location' => $booking->pickup_location,
            'destination_location' => $booking->destination_location,
            'pickup_point' => $booking->pickup_point,
            'destination_point' => $booking->destination_point,
            'distance' => $booking->distance,
            'vehicle' => $booking->vehicle,
            'driver' => $booking->driver,
            'booking_system' => $booking->booking_system,
            'bidding_fallback' => (bool) $booking->bidding_fallback,
            'pickup_plot_id' => $booking->pickup_plot_id,
            'dispatcher_action' => $booking->dispatcher_action,
            'reminder_minutes' => $booking->reminder_minutes,
            'dispatch_release_at' => $this->preBookingService->formatStoredDateTimeForCompany($booking->dispatch_release_at),
            'dispatch_release_mode' => $booking->dispatch_release_mode,
            'dispatch_release_override' => (bool) $booking->dispatch_release_override,
            'dispatch_released' => (bool) $booking->dispatch_released,
            'pending_driver_id' => $booking->pending_driver_id,
        ];
    }

    private function applyDriverBookingDeductions(CompanyDriver $driver, CompanySetting $companySetting): void
    {
        if ($companySetting->package_type == "ride_count_price") {
            $driver->ride_count_price -= 1;
            $driver->save();
        }

        if ($companySetting->package_type == "per_ride_commission_topup") {
            $checkAmount = $companySetting->package_amount;
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
    }
}
