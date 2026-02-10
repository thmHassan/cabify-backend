<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\FCMService;
use App\Models\CompanyBooking;
use App\Models\CompanyToken;
use App\Models\CompanyNotification;
use App\Models\CompanyDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\CompanySendNewRide;

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
                        if($booking->pickup_time > now()->format('H:i:s')){
                            $pickup_time = $booking->pickup_time;
                            $booking_date = $booking->booking_date;
                        }
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
                                'note' => $booking->note,
                                'pickup_time' => (isset($pickup_time) && $pickup_time != NULL) ? $pickup_time : NULL,
                                'booking_date' => (isset($booking_date) && $booking_date != NULL) ? $booking_date : NULL,
                            ]
                        ]);

                        $sendRide = new CompanySendNewRide;
                        $sendRide->booking_id = $booking->id;
                        $sendRide->driver_id = $driver->id;
                        $sendRide->save();

                        // $notification = new CompanyNotification;
                        // $notification->user_type = "driver";
                        // $notification->user_id = $driver->id;
                        // $notification->title = 'New Ride Available for Bidding';
                        // $notification->message = 'Place your bid now';
                        // $notification->save();
                                    
                        // $tokens = CompanyToken::where("user_id", $driver->id)->where("user_type", "driver")->get();

                        // if(isset($tokens) && $tokens != NULL){
                        //     foreach($tokens as $key => $token){
                        //         FCMService::sendToDevice(
                        //             $token->fcm_token,
                        //             'New Ride Available for Bidding 🚖',
                        //             'Place your bid now',
                        //             [
                        //                 'booking_id' => $booking->id,
                        //             ]
                        //         );
                        //     }
                        // }
                    }
                });
        }
        catch(\Exception $e){
            \Log::info("Bidding Notification");
            \Log::info($e->getMessage());
        }
    }
}
