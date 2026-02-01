<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\FCMService;
use App\Models\CompanyBooking;
use App\Models\CompanyDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class SendBiddingNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $bookingId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try{
            $booking = CompanyBooking::where("id",$this->bookingId)->with('userDetail')->first();

            if(isset($booking->driver) && $booking->driver != NULL && $booking->driver != ""){
                return;
            }
            $pickupLat = $booking->pickup_latitude;
            $pickupLng = $booking->pickup_longitude;

            CompanyDriver::select('*')
                ->selectRaw("
                (6371 * acos(
                    cos(radians(?)) 
                    * cos(radians(latitude)) 
                    * cos(radians(longitude) - radians(?)) 
                    + sin(radians(?)) 
                    * sin(radians(latitude))
                )) AS distance
                ", [$pickupLat, $pickupLng, $pickupLat])
                ->where('driving_status', 'idle')
                ->orderBy('distance')
                ->chunk(100, function ($drivers) use ($booking) {
                    foreach ($drivers as $driver) {
                        // if (!$driver->device_token) continue;
                        // FCMService::sendToDevice(
                        //     $driver->device_token,
                        //     'New Ride Available for Bidding 🚖',
                        //     'Place your bid now',
                        //     [
                        //         'booking_id' => $booking->id,
                        //     ]
                        // ); 
                        Http::withHeaders([
                            'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                        ])->post(env('NODE_SOCKET_URL') . '/send-new-ride', [
                            'drivers' => [$driver->id],
                            'booking' => [
                                'id' => $booking->id,
                                'booking_id' => $booking->booking_id,
                                'pickup_point' => $booking->pickup_point,
                                'destination_point' => $booking->destination_point,
                                'offered_amount' => $booking->offered_amount,
                                'distance' => $booking->distance,
                                'user_id' => $booking->user_id,
                                'user_name' => $booking->name,
                                'user_profile' => $booking->userDetail->profile_image,
                                'pickup_location' => $booking->pickup_location,
                                'destination_location' => $booking->destination_location,
                            ]
                        ]);
                    }
                });
        }
        catch(\Exception $e){
            \Log::info("Bidding Notification");
            \Log::info($e->getMessage());
        }
    }
}
