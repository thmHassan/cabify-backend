<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\CompanyBooking;
use App\Models\CompanyLostFound;
use Illuminate\Http\Request;

class LostFoundController extends Controller
{
    public function createLostFound(Request $request)
    {
        try {
            $request->validate([
                'booking_id' => 'required',
                'description' => 'nullable|string',
                'descrition' => 'nullable|string',
            ]);

            $riderId = auth('rider')->user()->id;

            $booking = CompanyBooking::where(function ($query) use ($request) {
                    $query->where('id', $request->booking_id)
                        ->orWhere('booking_id', $request->booking_id);
                })
                ->where('user_id', $riderId)
                ->first();

            if (!$booking) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Booking not found for this rider'
                ], 404);
            }

            $lostFound = new CompanyLostFound;
            $lostFound->user_id = $riderId;
            $lostFound->user_type = 'user';
            $lostFound->booking_id = $booking->id;
            $lostFound->status = 'lost';
            $lostFound->descrition = $request->description ?? $request->descrition;
            $lostFound->save();

            $lostFound->load(['bookingDetails', 'bookingDetails.userDetail', 'bookingDetails.driverDetail']);

            return response()->json([
                'success' => 1,
                'message' => 'Lost & found created successfully',
                'data' => $lostFound
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function listLostFound(Request $request)
    {
        try {
            $query = CompanyLostFound::where('user_type', 'user')
                ->where('user_id', auth('rider')->user()->id)
                ->with(['bookingDetails', 'bookingDetails.userDetail', 'bookingDetails.driverDetail'])
                ->orderBy('id', 'DESC');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            return response()->json([
                'success' => 1,
                'list' => $query->paginate(10)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
