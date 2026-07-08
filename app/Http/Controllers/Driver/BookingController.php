<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyBooking;
use App\Models\CompanyRating;
use App\Models\CompanyBid;
use App\Models\CompanySetting;
use App\Models\CompanyDriver;
use App\Models\VehicleType;
use App\Models\DriverPackage;
use App\Models\CompanyWaitingTimeLog;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Models\CompanyToken;
use App\Services\FCMService;
use App\Models\CompanyNotification;
use App\Models\Setting;
use App\Models\TenantUser;
use App\Models\CompanyUser;
use App\Models\WalletTransaction;
use App\Models\CompanySendNewRide;
use App\Models\BookingDispatchCycle;
use App\Services\SocketApiUrlResolver;
use App\Support\NearestDispatch;
use App\Support\PlotDispatch;
use App\Support\VehicleDispatchFilter;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function completedRide(Request $request)
    {
        try {
            $query = CompanyBooking::where("booking_status", "completed")->where("driver", auth('driver')->user()->id);
            if (isset($request->date) && $request->date != NULL) {
                $query->where("booking_date", $request->date);
            }
            $completedRides = $query->with(['userDetail', 'driverDetail', 'ratingDetail', 'waitingDetail'])->orderBy("booking_date", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $completedRides
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }
    public function cancelledRide(Request $request)
    {
        try {
            $query = CompanyBooking::where("booking_status", "cancelled")->where("driver", auth('driver')->user()->id);
            if (isset($request->date) && $request->date != NULL) {
                $query->whereDate("booking_date", $request->date);
            }
            $cancelledRides = $query->with(['userDetail', 'driverDetail', 'ratingDetail'])->orderBy("booking_date", "DESC")->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $cancelledRides
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }
    public function upcomingRide(Request $request)
    {
        try {
            $driverId = auth('driver')->user()->id;
            $includeAssignedOffers = filter_var($request->query('assigned_offers', false), FILTER_VALIDATE_BOOLEAN);

            $query = CompanyBooking::where("booking_status", "pending");
            $this->applyUpcomingRideDriverFilter($query, $driverId, $includeAssignedOffers);
            if (isset($request->date) && $request->date != NULL) {
                $query->whereDate("booking_date", $request->date);
            }
            $pendingRides = $query->with(['userDetail', 'driverDetail', 'ratingDetail'])->orderBy("booking_date", "DESC")->paginate(10);
            $this->attachDisplayDistanceToPaginator($pendingRides, $request);

            return response()->json([
                'success' => 1,
                'list' => $pendingRides
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    private function applyUpcomingRideDriverFilter($query, int $driverId, bool $includeAssignedOffers): void
    {
        if (!$includeAssignedOffers) {
            $query->where("driver", $driverId);
            return;
        }

        $query->where(function ($q) use ($driverId) {
            $q->where("pending_driver_id", $driverId)
                ->orWhere(function ($legacy) use ($driverId) {
                    $legacy->where("driver", $driverId)
                        ->where(function ($decision) {
                            $decision->whereNull("pickup_time_type")
                                ->orWhere("pickup_time_type", "!=", "time")
                                ->orWhereNull("is_scheduled")
                                ->orWhere("is_scheduled", false)
                                ->orWhereNull("dispatch_released")
                                ->orWhere("dispatch_released", false);
                        });
                });
        });
    }

    public function rateRide(Request $request)
    {
        try {
            $request->validate([
                'booking_id' => 'required',
                'rating' => 'required'
            ]);

            $rating = new CompanyRating;
            $rating->booking_id = $request->booking_id;
            $rating->user_type = "driver";
            $rating->rating = $request->rating;
            $rating->save();

            return response()->json([
                'success' => 1,
                'message' => 'Customer ratings given successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function listRideForBidding(Request $request)
    {
        try {
            $driver = auth('driver')->user();
            $rideList = CompanyBooking::whereNull("driver")
                ->where(function ($query) {
                    $query->where("pickup_time", "asap")
                        ->orWhere("pickup_time_type", "asap");
                })
                ->where("booking_status", "pending")
                ->where(function ($query) use ($driver) {
                    $query->whereNull('vehicle')
                        ->orWhere('vehicle', '');

                    if (filled($driver?->assigned_vehicle)) {
                        $query->orWhere('vehicle', (string) $driver->assigned_vehicle);
                    }
                })
                ->orderBy("id", "DESC")
                ->with("userDetail")
                ->get();
            $placedBidIds = CompanyBid::where("driver_id", $driver->id)
                ->whereIn("booking_id", $rideList->pluck("id"))
                ->pluck("booking_id")
                ->map(fn ($id) => (string) $id)
                ->all();

            $rideList->transform(function ($ride) use ($placedBidIds, $request) {
                $ride->placed = in_array((string) $ride->id, $placedBidIds, true);
                $this->attachDisplayDistance($ride, $request);
                return $ride;
            });

            return response()->json([
                'success' => 1,
                'list' => $rideList
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function rideDetail(Request $request)
    {
        try {
            $rideDetail = CompanyBooking::where("id", $request->ride_id)->with(['userDetail', 'ratingDetail'])->first();

            return response()->json([
                'success' => 1,
                'rideDetail' => $rideDetail
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function placeBid(Request $request)
    {
        try {

            $request->validate([
                'booking_id' => 'required',
                'amount' => 'required'
            ]);

            $existBid = CompanyBid::where('booking_id', $request->booking_id)
            ->where('driver_id', auth("driver")->user()->id)
            ->exists();

            if ($existBid) {
                return response()->json([
                    'error' => 1,
                    'message' => 'You have already placed a bid for this booking'
                ], 400);
            }

            $companySetting = CompanySetting::orderBy("id", "DESC")->first();
            if ($companySetting->package_type == "per_ride_commission_topup") {
                $checkAmount = $companySetting->package_amount;
                if ($checkAmount > auth("driver")->user()->wallet_balance) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Your wallet balance is not sufficient'
                    ], 400);
                }
            }
            if ($companySetting->package_type == "ride_count_price") {
                if(auth("driver")->user()->ride_count_price <= 0){
                    return response()->json([
                        'error' => 1,
                        'message' => 'Your ride count is not sufficient'
                    ], 400);
                }
            }
            if ($companySetting->package_type == "packages_topup") {
                $package = DriverPackage::where("driver_id", auth("driver")->user()->id)->where("package_type", "packages_topup")->orderBy("id", "DESC")->first();

                if (!isset($package) || (isset($package) && $package->expire_date < date("Y-m-d"))) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Your wallet balance is not sufficient'
                    ], 400);
                }
            }

            $newBid = new CompanyBid;
            $newBid->booking_id = $request->booking_id;
            $newBid->driver_id = auth("driver")->user()->id;
            $newBid->amount = $request->amount;
            $newBid->save();

            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            if ($booking && !VehicleDispatchFilter::driverMatchesBooking(auth('driver')->user(), $booking)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'This job is only available for the selected vehicle type.',
                ], 403);
            }
            $vehicle = VehicleType::where("id", auth("driver")->user()->vehicle_type)->first();
            $vehicleTypeName = $vehicle?->vehicle_type_name
                ?? auth("driver")->user()->vehicle_name
                ?? $booking?->vehicle
                ?? '';

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/place-bid', [
                        'userId' => $booking->user_id,
                        'bid' => [
                            'amount' => $newBid->amount,
                            'driver_name' => auth("driver")->user()->name,
                            'profile_image' => auth("driver")->user()->profile_image,
                            'vehicle_name' => auth("driver")->user()->vehicle_name,
                            'vehicle_type' => $vehicleTypeName,
                            'rating' => auth("driver")->user()->rating,
                            'bid_id' => $newBid->id
                        ]
                    ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if (isset($dataCheck) && $dataCheck->data['push_notification'] == "enable") {
                $notification = new CompanyNotification;
                $notification->user_type = "rider";
                $notification->user_id = $booking->user_id;
                $notification->title = 'New Bid';
                $notification->message = 'New bid is placed by driver';
                $notification->save();

                $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();

                if (isset($tokens) && $tokens != NULL) {
                    foreach ($tokens as $key => $token) {
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            'New Bid',
                            'New bid is placed by driver',
                            [
                                'bid_id' => $newBid->id,
                            ]
                        );
                    }
                }
            }

            return response()->json([
                'success' => 1,
                'message' => 'Bid placed succesfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function cancelConfirmRide(Request $request)
    {
        try {
            $request->validate([
                'booking_id' => 'required',
                'cancel_reason' => 'required',
            ]);

            $setting = CompanySetting::orderBy("id", "DESC")->first();

            if ($setting->cancellation_per_day <= auth("driver")->user()->cancel_rides_per_day) {
                return response()->json([
                    'error' => 1,
                    'message' => "You have reached cancellation limit per day"
                ], 400);
            }

            $booking = CompanyBooking::where("id", $request->booking_id)->first();

            if (isset($booking) && $booking != NULL) {
                $booking->booking_status = "cancelled";
                $booking->cancel_reason = $request->cancel_reason;
                $booking->cancelled_by = 'driver';
                $booking->save();

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                    'database' => $request->header('database'),
                ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/driver/cancel-ride', [
                            'ride_id' => $booking->id,
                            'cancel_reason' => $request->cancel_reason,
                        ]);

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                    'database' => $request->header('database'),
                ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/change-ride-status', [
                            'userId' => $booking->user_id,
                            'status' => "cancel_confirm_ride",
                            'database' => $request->header('database'),
                            'booking' => [
                                'id' => $booking->id,
                                'booking_id' => $booking->booking_id,
                                'pickup_point' => $booking->pickup_point,
                                'destination_point' => $booking->destination_point,
                                'offered_amount' => $booking->offered_amount,
                                'distance' => $booking->distance,
                                'booking_status' => $booking->booking_status
                            ]
                        ]);

                $dataCheck = (new TenantUser)
                    ->setConnection('central')
                    ->where("id", $request->header('database'))
                    ->first();

                if (isset($dataCheck) && $dataCheck->data['push_notification'] == "enable") {
                    $notification = new CompanyNotification;
                    $notification->user_type = "rider";
                    $notification->user_id = $booking->user_id;
                    $notification->title = 'Cancel Ride';
                    $notification->message = 'Your ride has been cancelled by driver';
                    $notification->save();

                    $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();

                    if (isset($tokens) && $tokens != NULL) {
                        foreach ($tokens as $key => $token) {
                            FCMService::sendToDevice(
                                $token->fcm_token,
                                'Cancel Ride',
                                'Your ride has been cancelled by driver',
                                [
                                    'booking_id' => $booking->id,
                                ]
                            );
                        }
                    }
                }
            }

            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $driver->driving_status = "idle";
            $driver->cancel_rides_per_day += 1;
            
            $companySetting = CompanySetting::orderBy("id", "DESC")->first();
            if ($companySetting->package_type == "ride_count_price") {
                $driver->ride_count_price += 1;
            }
            if ($companySetting->package_type == "per_ride_commission_topup") {
                $checkAmount = $companySetting->package_amount;
                $driver->wallet_balance += $checkAmount;
            }
            $driver->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/waiting-driver', [
                        'clientId' => $request->header('database'),
                        'driver_id' => auth("driver")->user()->id,
                    ]);

            return response()->json([
                'success' => 1,
                'message' => 'Ride cancelled succesfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function acceptRide(Request $request)
    {
        try {
            $booking = CompanyBooking::where("id", $request->ride_id)->with('userDetail')->first();

            if (!$booking) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Booking not found',
                ], 404);
            }

            if ($booking->booking_status !== 'pending') {
                return response()->json([
                    'error' => 1,
                    'message' => 'Ride already accepted or cancelled'
                ], 400);
            }

            $driverId = auth('driver')->user()->id;
            $isNearestDispatchOffer = NearestDispatch::isActiveOffer($booking->dispatcher_action);
            $isPlotDispatchOffer = PlotDispatch::isActiveOffer($booking->dispatcher_action);
            $isPlotBiddingFallback = BookingDispatchCycle::where('booking_id', $booking->id)
                ->where('status', 'exhausted')
                ->where('fallback_to_bidding', true)
                ->exists();

            if (!VehicleDispatchFilter::driverMatchesBooking(auth('driver')->user(), $booking)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'This job is only available for the selected vehicle type.',
                ], 403);
            }

            if (!$isNearestDispatchOffer && !$isPlotDispatchOffer && !$isPlotBiddingFallback && $booking->driver && (string) $booking->driver !== (string) $driverId) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Ride already accepted by another driver',
                ], 400);
            }

            $companySetting = CompanySetting::orderBy("id", "DESC")->first();
            if ($companySetting->package_type == "per_ride_commission_topup") {
                $checkAmount = $companySetting->package_amount;
                if ($checkAmount > auth("driver")->user()->wallet_balance) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Your wallet balance is not sufficient'
                    ], 400);
                }
            }
            if ($companySetting->package_type == "ride_count_price") {
                if(auth("driver")->user()->ride_count_price <= 0){
                    return response()->json([
                        'error' => 1,
                        'message' => 'Your ride count is not sufficient'
                    ], 400);
                }
            }
            if ($companySetting->package_type == "packages_topup") {
                $package = DriverPackage::where("driver_id", auth("driver")->user()->id)
                    ->where("package_type", "packages_postpaid")
                    ->orderBy("id", "DESC")
                    ->first();

                if (!isset($package) || (isset($package) && $package->expire_date < date("Y-m-d"))) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Your wallet balance is not sufficient'
                    ], 400);
                }
            }

            $bookingAmount = (is_null($booking->booking_amount) || $booking->booking_amount == 0)
                ? $booking->offered_amount
                : $booking->booking_amount;

            $newStatus = $this->resolveStatusAfterDriverAccept($booking, $isNearestDispatchOffer);

            $claimed = DB::transaction(function () use ($request, $booking, $driverId, $bookingAmount, $newStatus, $isNearestDispatchOffer, $isPlotDispatchOffer, $isPlotBiddingFallback) {
                if ($isPlotDispatchOffer) {
                    $cycle = BookingDispatchCycle::where('booking_id', $booking->id)
                        ->where('status', 'in_progress')
                        ->lockForUpdate()
                        ->first();

                    if (!$cycle) {
                        return false;
                    }

                    if ((string) $cycle->current_driver_id !== (string) $driverId) {
                        return false;
                    }

                    if ($cycle->offer_expires_at && $cycle->offer_expires_at->isPast()) {
                        return false;
                    }

                    if ($request->filled('offer_token') && (int) $request->input('offer_token') !== (int) $cycle->offer_token) {
                        return false;
                    }

                    $cycle->update([
                        'status' => 'accepted',
                        'current_driver_id' => $driverId,
                        'offer_expires_at' => null,
                    ]);
                } elseif ($isPlotBiddingFallback) {
                    $cycle = BookingDispatchCycle::where('booking_id', $booking->id)
                        ->where('status', 'exhausted')
                        ->where('fallback_to_bidding', true)
                        ->lockForUpdate()
                        ->first();

                    if (!$cycle) {
                        return false;
                    }

                    $wasNotified = CompanySendNewRide::where('booking_id', $booking->id)
                        ->where('driver_id', $driverId)
                        ->exists();

                    if (!$wasNotified) {
                        return false;
                    }

                    $cycle->update([
                        'status' => 'accepted',
                        'current_driver_id' => $driverId,
                        'current_driver_rank' => null,
                        'offer_expires_at' => null,
                    ]);
                }

                return (bool) CompanyBooking::where('id', $booking->id)
                    ->where('booking_status', 'pending')
                    ->where(function ($query) use ($driverId, $isNearestDispatchOffer, $isPlotDispatchOffer, $isPlotBiddingFallback) {
                        if ($isPlotDispatchOffer) {
                            $query->where('pending_driver_id', $driverId)
                                ->whereNull('driver');
                        } elseif ($isNearestDispatchOffer) {
                            $query->where(function ($inner) use ($driverId) {
                                $inner->whereNull('driver')->orWhere('driver', $driverId);
                            });
                        } elseif ($isPlotBiddingFallback) {
                            $query->whereNull('driver');
                        } else {
                            $query->whereNull('driver')
                                ->orWhere('driver', $driverId)
                                ->orWhere('pending_driver_id', $driverId);
                        }
                    })
                    ->update([
                        'driver' => $driverId,
                        'pending_driver_id' => null,
                        'booking_amount' => $bookingAmount,
                        'booking_status' => $newStatus,
                        'dispatch_released' => $this->shouldResolveAcceptedScheduledRelease($booking, $newStatus)
                            ? true
                            : $booking->dispatch_released,
                        'dispatcher_action' => $isNearestDispatchOffer
                            ? "Nearest dispatch — accepted by driver #{$driverId}"
                            : ($isPlotDispatchOffer
                                ? PlotDispatch::acceptedAction($driverId)
                                : ($isPlotBiddingFallback
                                    ? "Fixed-fare bidding fallback — accepted by driver #{$driverId}"
                                    : "Manual assignment accepted by driver #{$driverId}")),
                    ]);
            });

            if (!$claimed) {
                return response()->json([
                    'error' => 1,
                    'message' => ($isPlotDispatchOffer || $isPlotBiddingFallback)
                        ? 'Ride no longer available'
                        : 'Ride already accepted by another driver',
                ], 409);
            }

            $booking->refresh();
            $driver = CompanyDriver::where("id", $driverId)->first();

            if ($companySetting->package_type == "ride_count_price") {
                $driver->ride_count_price -= 1;
            }

            if (in_array($newStatus, ['ongoing', 'started'], true)) {
                $driver->driving_status = "busy";
            }

            if ($companySetting->package_type == "per_ride_commission_topup") {
                $checkAmount = $companySetting->package_amount;
                $driver->wallet_balance -= $checkAmount;

                $wallet = new WalletTransaction;
                $wallet->user_type = "driver";
                $wallet->user_id = $driver->id;
                $wallet->type = 'deduct';
                $wallet->amount = $checkAmount;
                $wallet->comment = "Per ride booking deduction";
                $wallet->save();
            }
            $driver->save();

            $user = CompanyUser::where("phone_no", $booking->phone_no)->first();
            if (!isset($user) || $user == NULL) {
                $user = new CompanyUser;
                $user->name = $booking->name;
                $user->email = $booking->email;
                $user->phone_no = $booking->phone_no;
                $user->save();
            }

            $socketHeaders = [
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ];

            Http::withHeaders($socketHeaders)->post(
                SocketApiUrlResolver::endpoint(null, 'driver/accept-ride'),
                [
                    'ride_id' => $booking->id,
                    'driver_id' => $driverId,
                ]
            );

            Http::withHeaders($socketHeaders)->post(
                SocketApiUrlResolver::endpoint(null, 'change-ride-status'),
                [
                    'userId' => $booking->user_id,
                    'status' => "accept_ride",
                    'booking' => [
                        'id' => $booking->id,
                        'booking_id' => $booking->booking_id,
                        'pickup_point' => $booking->pickup_point,
                        'destination_point' => $booking->destination_point,
                        'offered_amount' => $booking->offered_amount,
                        'distance' => $booking->distance,
                        'booking_status' => $booking->booking_status
                    ]
                ]
            );

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if (isset($dataCheck) && $dataCheck->data['push_notification'] == "enable") {
                $notification = new CompanyNotification;
                $notification->user_type = "rider";
                $notification->user_id = $booking->user_id;
                $notification->title = 'Accept Ride';
                $notification->message = 'Your ride has been accepted by driver';
                $notification->save();

                $tokens = CompanyToken::where("user_id", $booking->user_id)
                    ->where("user_type", "rider")
                    ->get();

                if (isset($tokens) && $tokens != NULL) {
                    foreach ($tokens as $key => $token) {
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            'Accept Ride',
                            'Your ride has been accepted by driver',
                            [
                                'booking_id' => $booking->id,
                            ]
                        );
                    }
                }
            }

            return response()->json([
                'success' => 1,
                'message' => 'Ride accepted successfully',
                'booking_status' => $booking->booking_status,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function resolveStatusAfterDriverAccept(CompanyBooking $booking, bool $isNearestDispatchOffer): string
    {
        if ($isNearestDispatchOffer) {
            return 'started';
        }

        if ($booking->pickup_time === 'asap' || ($booking->pickup_time_type ?? null) === 'asap') {
            return 'ongoing';
        }

        try {
            $bookingDateTime = Carbon::parse($booking->booking_date . ' ' . $booking->pickup_time);
            if ($bookingDateTime->between(now()->subMinutes(30), now()->addMinutes(30))) {
                return 'ongoing';
            }
        } catch (\Exception $e) {
            return ($booking->pickup_time_type ?? null) === 'time' || (bool) $booking->is_scheduled
                ? 'pending'
                : 'ongoing';
        }

        return ($booking->pickup_time_type ?? null) === 'time' || (bool) $booking->is_scheduled
            ? 'pending'
            : 'started';
    }

    private function shouldResolveAcceptedScheduledRelease(CompanyBooking $booking, string $newStatus): bool
    {
        if ($newStatus !== 'pending') {
            return false;
        }

        return ($booking->pickup_time_type ?? null) === 'time' || (bool) $booking->is_scheduled;
    }

    private function isRejectableManualAssignment(CompanyBooking $booking, $driverId): bool
    {
        if (
            (string) $booking->driver !== (string) $driverId
            && (string) $booking->pending_driver_id !== (string) $driverId
        ) {
            return false;
        }

        if (!in_array($booking->booking_status, ['pending', 'ongoing'], true)) {
            return false;
        }

        $action = strtolower((string) $booking->dispatcher_action);
        return str_contains($action, 'assigned')
            || str_contains($action, 'pre-job')
            || str_contains($action, 'manual')
            || str_contains($action, 'driver selected')
            || str_contains($action, 'dispatching now');
    }

    private function driverWasOfferedRide(CompanyBooking $booking, $driverId): bool
    {
        if (
            (string) $booking->driver === (string) $driverId
            || (string) $booking->pending_driver_id === (string) $driverId
        ) {
            return true;
        }

        return CompanySendNewRide::where('booking_id', $booking->id)
            ->where('driver_id', $driverId)
            ->exists();
    }

    private function postDriverOfferDecisionToSocket(Request $request, CompanyBooking $booking, $driverId, string $endpoint)
    {
        $response = Http::withHeaders([
            'database' => $request->header('database'),
            'Accept' => 'application/json',
        ])->post(SocketApiUrlResolver::endpoint(null, 'bookings/' . $booking->id . '/' . $endpoint), [
            'driver_id' => $driverId,
        ]);

        if (!$response->successful()) {
            $message = $response->json('message') ?? 'Failed to process ride offer decision';
            return response()->json([
                'error' => 1,
                'message' => $message,
            ], $response->status());
        }

        return null;
    }

    public function rejectRide(Request $request)
    {
        try {
            $request->validate([
                'ride_id' => 'required',
            ]);

            $driverId = auth('driver')->user()->id;
            $booking = CompanyBooking::where('id', $request->ride_id)->first();

            if (!isset($booking) || $booking == null) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Booking not found',
                ], 404);
            }

            $wasOffered = $this->driverWasOfferedRide($booking, $driverId);

            if ($booking->booking_status !== 'pending' && !$this->isRejectableManualAssignment($booking, $driverId)) {
                return response()->json([
                    'success' => 1,
                    'message' => 'Ride is no longer available',
                    'skipped' => true,
                ]);
            }

            if (
                (string) $booking->driver !== (string) $driverId
                && (string) $booking->pending_driver_id !== (string) $driverId
                && !$wasOffered
            ) {
                return response()->json([
                    'error' => 1,
                    'message' => 'You are not assigned to this ride',
                ], 400);
            }

            $socketError = $this->postDriverOfferDecisionToSocket($request, $booking, $driverId, 'auto-dispatch/reject');
            if ($socketError) {
                return $socketError;
            }

            return response()->json([
                'success' => 1,
                'message' => 'Ride rejected successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function expireRideOffer(Request $request)
    {
        try {
            $request->validate([
                'ride_id' => 'required',
            ]);

            $driverId = auth('driver')->user()->id;
            $booking = CompanyBooking::where('id', $request->ride_id)->first();

            if (!isset($booking) || $booking == null) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Booking not found',
                ], 404);
            }

            if ($booking->booking_status !== 'pending' || !$this->driverWasOfferedRide($booking, $driverId)) {
                return response()->json([
                    'success' => 1,
                    'message' => 'Ride offer is no longer available',
                    'skipped' => true,
                ]);
            }

            $endpoint = $this->isRejectableManualAssignment($booking, $driverId)
                ? 'manual-assignment/expire'
                : 'auto-dispatch/reject';

            $socketError = $this->postDriverOfferDecisionToSocket($request, $booking, $driverId, $endpoint);
            if ($socketError) {
                return $socketError;
            }

            return response()->json([
                'success' => 1,
                'message' => 'Ride offer expired successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function currentRide(Request $request)
    {
        try {
            $booking = CompanyBooking::where("driver", auth('driver')->user()->id)
                ->where(function ($q) {
                    $q->where("booking_status", 'started')
                        ->orWhere("booking_status", 'arrived')
                        ->orWhere("booking_status", 'ongoing');
                })->with(['userDetail', 'vehicleDetail', 'waitingDetail'])->first();

            return response()->json([
                'success' => 1,
                'currentRide' => $booking
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function arrivedStatus(Request $request)
    {
        try {
            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            $booking->booking_status = "arrived";
            $booking->save();

            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $driver->driving_status = "busy";
            $driver->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/change-ride-status', [
                        'userId' => $booking->user_id,
                        'status' => "arrived_driver",
                        'database' => $request->header('database'),
                        'booking' => [
                            'id' => $booking->id,
                            'booking_id' => $booking->booking_id,
                            'driver' => $booking->driver ?: auth("driver")->user()->id,
                            'pickup_point' => $booking->pickup_point,
                            'destination_point' => $booking->destination_point,
                            'offered_amount' => $booking->offered_amount,
                            'distance' => $booking->distance,
                            'booking_status' => $booking->booking_status
                        ]
                    ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if (isset($dataCheck) && $dataCheck->data['push_notification'] == "enable") {
                $notification = new CompanyNotification;
                $notification->user_type = "rider";
                $notification->user_id = $booking->user_id;
                $notification->title = 'Arrived Ride';
                $notification->message = 'Driver is arrived at your pickup location';
                $notification->save();

                $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();

                if (isset($tokens) && $tokens != NULL) {
                    foreach ($tokens as $key => $token) {
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            'Arrived Driver',
                            'Driver is arrived at your pickup location',
                            [
                                'booking_id' => $booking->id,
                            ]
                        );
                    }
                }
            }

            return response()->json([
                'success' => 1,
                'message' => 'User received notification that you arrived'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function waitingTime(Request $request)
    {
        try {
            $request->validate([
                'booking_id' => 'required'
            ]);

            $waitingRecord = CompanyWaitingTimeLog::where("booking_id", $request->booking_id)->where("status", 'start')->orderBy("id", "DESC")->first();
            $booking = CompanyBooking::where("id", $request->booking_id)->first();

            if (!isset($waitingRecord) || $waitingRecord == NULL) {
                $waitingRecord = new CompanyWaitingTimeLog;
                $waitingRecord->booking_id = $request->booking_id;
                $waitingRecord->start_time = date("Y-m-d H:i:s");
                $waitingRecord->status = "start";
                $waitingRecord->save();

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                    'database' => $request->header('database'),
                ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/waiting-time-event', [
                    'userId' => $booking->user_id,
                    'status' => "start",
                    'database' => $request->header('database'),
                    'booking' => [
                        'id' => $booking->id,
                        'booking_id' => $booking->booking_id,
                        'pickup_point' => $booking->pickup_point,
                        'destination_point' => $booking->destination_point,
                        'offered_amount' => $booking->offered_amount,
                        'distance' => $booking->distance,
                    ]
                ]);

                return response()->json([
                    'success' => 1,
                    'message' => 'Waiting time has started'
                ]);
            } else {
                $setting = CompanySetting::orderBy("id", "DESC")->first();
                $waitingRecord->end_time = date("Y-m-d H:i:s");
                $waitingRecord->status = "stop";
                $waitingRecord->save();

                $start = Carbon::parse($waitingRecord->start_time);
                $end = Carbon::parse($waitingRecord->end_time);
                $waitingMinutes = $start->diffInMinutes($end);

                $amount = $waitingMinutes * $setting->waiting_time_charge;
                $booking = CompanyBooking::where("id", $request->booking_id)->first();
                $booking->booking_amount += $amount;
                $booking->waiting_time += $waitingMinutes;
                $booking->waiting_amount += $amount;
                $booking->save();
                $waitingRecord->charge = $amount;
                $waitingRecord->save();

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                    'database' => $request->header('database'),
                ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/waiting-time-event', [
                    'userId' => $booking->user_id,
                    'status' => "stop",
                    'database' => $request->header('database'),
                    'booking' => [
                        'id' => $booking->id,
                        'booking_id' => $booking->booking_id,
                        'pickup_point' => $booking->pickup_point,
                        'destination_point' => $booking->destination_point,
                        'offered_amount' => $booking->offered_amount,
                        'distance' => $booking->distance,
                    ]
                ]);

                return response()->json([
                    'success' => 1,
                    'message' => 'Waiting time has stopped',
                    'updated_amount' => $booking->booking_amount
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function noShowRide(Request $request)
    {
        try {
            $request->validate([
                'booking_id' => 'required',
            ]);

            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $booking = CompanyBooking::where("id", $request->booking_id)
                ->where("driver", $driver->id)
                ->first();

            if (!$booking) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Booking not found for this driver',
                ], 404);
            }

            if ($booking->booking_status !== 'arrived') {
                return response()->json([
                    'error' => 1,
                    'message' => 'No show can only be marked after arriving at pickup',
                ], 422);
            }

            $booking->booking_status = "no_show";
            $booking->dispatcher_action = trim(($driver->name ?? 'Driver') . ' marked this ride as no show');
            $booking->save();

            $driver->driving_status = "idle";
            $driver->save();

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/change-ride-status', [
                'userId' => $booking->user_id,
                'status' => "driver_no_show",
                'database' => $request->header('database'),
                'booking' => [
                    'id' => $booking->id,
                    'booking_id' => $booking->booking_id,
                    'pickup_point' => $booking->pickup_point,
                    'pickup_location' => $booking->pickup_location,
                    'destination_point' => $booking->destination_point,
                    'destination_location' => $booking->destination_location,
                    'offered_amount' => $booking->offered_amount,
                    'distance' => $booking->distance,
                    'distance_value' => $this->displayDistanceFields($booking->distance, $request)['distance_value'],
                    'distance_unit' => $this->displayDistanceFields($booking->distance, $request)['distance_unit'],
                    'driver' => $booking->driver,
                    'booking_status' => $booking->booking_status,
                    'booking_date' => $booking->booking_date,
                    'pickup_time' => $booking->pickup_time,
                    'driverDetail' => [
                        'id' => $driver->id,
                        'name' => $driver->name,
                        'phone_no' => $driver->phone_no,
                    ],
                ],
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Ride marked as no show',
                'booking' => $booking,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function changeBookingPaymentStatus(Request $request)
    {
        try {
            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            $booking->payment_status = "completed";
            $booking->save();

            return response()->json([
                'success' => 1,
                'message' => 'Payment status marked as completed'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function verifyBookingOtp(Request $request)
    {
        try {
            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            // if ($booking->otp == $request->otp) {
                $booking->booking_status = "started";
                $booking->driver_pickup_time = now()->format('Y-m-d H:i:s');
                $booking->save();

                $driverId = $booking->driver ?: auth('driver')->user()->id;
                if ($driverId) {
                    CompanyDriver::where("id", $driverId)->update(["driving_status" => "busy"]);
                }

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                    'database' => $request->header('database'),
                ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/change-ride-status', [
                            'userId' => $booking->user_id,
                            'status' => "ride_started",
                            'database' => $request->header('database'),
                            'booking' => [
                                'id' => $booking->id,
                                'booking_id' => $booking->booking_id,
                                'driver' => $driverId,
                                'pickup_point' => $booking->pickup_point,
                                'destination_point' => $booking->destination_point,
                                'offered_amount' => $booking->offered_amount,
                                'distance' => $booking->distance,
                                'booking_status' => $booking->booking_status
                            ]
                        ]);

                $dataCheck = (new TenantUser)
                    ->setConnection('central')
                    ->where("id", $request->header('database'))
                    ->first();

                if (isset($dataCheck) && $dataCheck->data['push_notification'] == "enable") {
                    $notification = new CompanyNotification;
                    $notification->user_type = "rider";
                    $notification->user_id = $booking->user_id;
                    $notification->title = 'Ride Start';
                    $notification->message = 'Your ride has been started to your destination';
                    $notification->save();

                    $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();

                    if (isset($tokens) && $tokens != NULL) {
                        foreach ($tokens as $key => $token) {
                            FCMService::sendToDevice(
                                $token->fcm_token,
                                'Ride Start',
                                'Your ride has been started to your destination',
                                [
                                    'booking_id' => $booking->id,
                                ]
                            );
                        }
                    }
                }
                return response()->json([
                    'success' => 1,
                    'message' => 'OTP verified successfully'
                ]);
            // } else {
            //     return response()->json([
            //         'success' => 1,
            //         'message' => 'OTP unverified'
            //     ], 400);
            // }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function completeCurrentRide(Request $request)
    {
        try {
            $booking = CompanyBooking::where("id", $request->booking_id)->first();
            $booking->booking_status = "completed";
            $booking->driver_dropoff_time = now()->format('Y-m-d H:i:s');
            $booking->save();

            $driverId = $booking->driver ?: auth('driver')->user()->id;

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/change-ride-status', [
                        'userId' => $booking->user_id,
                        'status' => "complete_current_ride",
                        'database' => $request->header('database'),
                        'booking' => [
                            'id' => $booking->id,
                            'booking_id' => $booking->booking_id,
                            'driver' => $driverId,
                            'pickup_point' => $booking->pickup_point,
                            'destination_point' => $booking->destination_point,
                            'offered_amount' => $booking->offered_amount,
                            'distance' => $booking->distance,
                            'booking_status' => $booking->booking_status,
                            'waiting_time' => $booking->waiting_time,
                            'waiting_amount' => $booking->waiting_amount,
                        ]
                    ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            if (isset($dataCheck) && $dataCheck->data['push_notification'] == "enable") {

                $notification = new CompanyNotification;
                $notification->user_type = "rider";
                $notification->user_id = $booking->user_id;
                $notification->title = 'Ride Completed';
                $notification->message = 'Your ride has been completed. Please rate application';
                $notification->save();

                $tokens = CompanyToken::where("user_id", $booking->user_id)->where("user_type", "rider")->get();

                if (isset($tokens) && $tokens != NULL) {
                    foreach ($tokens as $key => $token) {
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            'Ride completed',
                            'Your ride has been completed. Please rate application',
                            [
                                'booking_id' => $booking->id,
                            ]
                        );
                    }
                }
            }

            $driver = CompanyDriver::where("id", $driverId)->first();
            if ($driver) {
                $driver->driving_status = "idle";
                $driver->save();
            }

            Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                'database' => $request->header('database'),
            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/waiting-driver', [
                'clientId' => $request->header('database'),
                'driver_id' => $driverId,
            ]);

            $settingData = CompanySetting::orderBy("id", "DESC")->first();
            
            // if(isset($booking->email) && $booking->email != NULL){
            //     if ($settingData->map_settings == "default") {
            //         $centralData = (new Setting)
            //             ->setConnection('central')
            //             ->orderBy("id", "DESC")
            //             ->first();

            //         $mail_server = $centralData->smtp_host;
            //         $mail_from = $centralData->smtp_from_address;
            //         $mail_user_name = $centralData->smtp_user_name;
            //         $mail_password = $centralData->smtp_password;
            //         $mail_port = 587;
            //     } else {
            //         $mail_server = $settingData->mail_server;
            //         $mail_from = $settingData->mail_from;
            //         $mail_user_name = $settingData->mail_user_name;
            //         $mail_password = $settingData->mail_password;
            //         $mail_port = $settingData->mail_port;
            //     }

            //     config([
            //         'mail.mailers.smtp.host' => $mail_server,
            //         'mail.mailers.smtp.port' => $mail_port,
            //         'mail.mailers.smtp.username' => $mail_user_name,
            //         'mail.mailers.smtp.password' => $mail_password,
            //         'mail.from.address' => $mail_from,
            //         'mail.from.name' => $mail_user_name,
            //     ]);

            //     Mail::send('emails.ride-complete', [
            //         'name' => $booking->name ?? 'User',
            //         'pickup_location' => $booking->pickup_location,
            //         'dropoff_location' => $booking->destination_location,
            //         'ride_date' => $booking->booking_date,
            //         'total_fare' => $booking->booking_amount,
            //     ], function ($message) use ($booking) {
            //         $message->to($booking->email)
            //             ->subject('Ride Completed');
            //     });
            // }
            
            // if(auth("driver")->user()->email && auth("driver")->user()->email != NULL){
            //     $settingData = CompanySetting::orderBy("id", "DESC")->first();
            //     if ($settingData->map_settings == "default") {
    
            //         $centralData = (new Setting)
            //             ->setConnection('central')
            //             ->orderBy("id", "DESC")
            //             ->first();
    
            //         $mail_server = $centralData->smtp_host;
            //         $mail_from = $centralData->smtp_from_address;
            //         $mail_user_name = $centralData->smtp_user_name;
            //         $mail_password = $centralData->smtp_password;
            //         $mail_port = 587;
            //     } else {
            //         $mail_server = $settingData->mail_server;
            //         $mail_from = $settingData->mail_from;
            //         $mail_user_name = $settingData->mail_user_name;
            //         $mail_password = $settingData->mail_password;
            //         $mail_port = $settingData->mail_port;
            //     }
    
            //     config([
            //         'mail.mailers.smtp.host' => $mail_server,
            //         'mail.mailers.smtp.port' => $mail_port,
            //         'mail.mailers.smtp.username' => $mail_user_name,
            //         'mail.mailers.smtp.password' => $mail_password,
            //         'mail.from.address' => $mail_from,
            //         'mail.from.name' => $mail_user_name,
            //     ]);
    
            //     Mail::send('emails.ride-complete', [
            //         'name' => auth("driver")->user()->name ?? 'User',
            //         'pickup_location' => $booking->pickup_location,
            //         'dropoff_location' => $booking->destination_location,
            //         'ride_date' => $booking->booking_date,
            //         'total_fare' => $booking->booking_amount,
            //     ], function ($message) {
            //         $message->to(auth("driver")->user()->email)
            //             ->subject('Ride Completed');
            //     });
            // }

            return response()->json([
                'success' => 1,
                'message' => 'Ride completed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function attachDisplayDistanceToPaginator($paginator, Request $request): void
    {
        $paginator->getCollection()->transform(function ($ride) use ($request) {
            $this->attachDisplayDistance($ride, $request);
            return $ride;
        });
    }

    private function attachDisplayDistance($ride, Request $request): void
    {
        foreach ($this->displayDistanceFields($ride->distance ?? null, $request) as $key => $value) {
            $ride->{$key} = $value;
        }
    }

    private function displayDistanceFields($storedDistance, Request $request): array
    {
        $unit = $this->tenantDistanceUnit($request);
        $distance = is_numeric($storedDistance) ? (float) $storedDistance : null;

        if ($distance === null) {
            return [
                'distance_value' => null,
                'distance_unit' => $unit,
            ];
        }

        $value = $distance >= 1000
            ? ($unit === 'miles' ? $distance / 1609.344 : $distance / 1000)
            : $distance;

        return [
            'distance_value' => round($value, 2),
            'distance_unit' => $unit,
        ];
    }

    private function tenantDistanceUnit(Request $request): string
    {
        $tenantId = $request->header('database');
        if ($tenantId) {
            $tenant = (new TenantUser)
                ->setConnection('central')
                ->where('id', $tenantId)
                ->first();
            $rawData = $tenant?->data ?? [];
            $tenantData = is_array($rawData) ? $rawData : json_decode((string) $rawData, true);

            if (strtolower((string) ($tenantData['units'] ?? '')) === 'miles') {
                return 'miles';
            }
        }

        return 'km';
    }
}
