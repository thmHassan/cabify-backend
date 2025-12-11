<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyPlot;
use App\Models\CompanyBooking;

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
                $polygon = json_decode(json_decode($rec->features, true), true);
                $array = $polygon['geometry']['coordinates'][0];
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
                'booking_system' => 'required',
                'booking_amount' => 'required',
            ]);

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
            $newBooking->save();

            return response()->json([
                'success' => 1,
                'message' => 'Booking created successfully'
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
                'destination_point' => 'required'
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
            $query = CompanyBooking::where("booking_status", 'cancelled')->orderBy("id", "DESC");

            if(isset($request->search) && $request->search != NULL){
                $query->where(function($q) use ($request){
                    $q->where("booking_id", "LIKE", "%".$request->search."%")
                      ->orWhere("name", "LIKE", "%".$request->search."%");
                });
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
            $rides = $query->paginate(10);

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
}
