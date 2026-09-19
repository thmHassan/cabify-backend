<?php

namespace App\Http\Controllers\Driver;

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

            $driverId = auth('driver')->user()->id;

            $bookingQuery = CompanyBooking::where(function ($query) use ($request) {
                    $query->where('id', $request->booking_id)
                        ->orWhere('booking_id', $request->booking_id);
                })
                ->where(function ($query) use ($driverId) {
                    $query->where('driver', $driverId)
                        ->orWhere('pending_driver_id', $driverId);
                });

            $booking = $bookingQuery->first();

            if (!$booking) {
                $requestedBooking = CompanyBooking::where(function ($query) use ($request) {
                        $query->where('id', $request->booking_id)
                            ->orWhere('booking_id', $request->booking_id);
                    })
                    ->select('id', 'booking_id', 'driver', 'pending_driver_id', 'booking_status')
                    ->first();

                return response()->json([
                    'error' => 1,
                    'message' => 'Booking not found for this driver',
                    'debug' => [
                        'requested_booking_id' => $request->booking_id,
                        'authenticated_driver_id' => $driverId,
                        'booking_found' => (bool) $requestedBooking,
                        'booking' => $requestedBooking,
                    ],
                ], 404);
            }

            $lostFound = new CompanyLostFound;
            $lostFound->user_id = $driverId;
            $lostFound->user_type = 'driver';
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
            $query = CompanyLostFound::where('user_type', 'driver')
                ->where('user_id', auth('driver')->user()->id)
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
