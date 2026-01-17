<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        $booking = CompanyBooking::where("id",$this->bookingId)->first();

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
                    if (!$driver->device_token) continue;
                    FCMService::sendToDevice(
                        $driver->device_token,
                        'New Ride Available for Bidding 🚖',
                        'Place your bid now',
                        [
                            'booking_id' => $booking->id,
                        ]
                    ); 
                }
            });

    }
}
