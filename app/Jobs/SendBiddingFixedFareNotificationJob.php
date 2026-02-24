<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\CompanyDriver;
use App\Models\CompanyBooking;
use App\Models\CompanyNotification;
use App\Models\CompanyPlot;
use App\Models\CompanyToken;
use App\Services\FCMService;
use App\Models\CompanyDispatchSystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Events\BookingShownOnDispatcher;
use App\Models\CompanySendNewRide;
use App\Models\Dispatcher;

class SendBiddingFixedFareNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $bookingId, public ?int $plotId, public int $count, public string $tenantDatabase )
    {

    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try{
            config([
                'database.connections.tenant.database' => "tenant".$this->tenantDatabase,
            ]);

            DB::purge('tenant');
            DB::reconnect('tenant');

            $booking = CompanyBooking::where("id",$this->bookingId)->with('userDetail')->first();

            if(isset($booking->driver) && $booking->driver != NULL && $booking->driver != ""){
                return;
            }

            $plotId = $this->plotId;
            if(!isset($plotId) || $plotId == NULL){
                $plotId = (int) $booking->pickup_plot_id;
            }

            $pickup_time = NULL;
            $booking_date = NULL;
            if($booking->pickup_time > now()->format('H:i:s')){
                $pickup_time = $booking->pickup_time;
                $booking_date = $booking->booking_date;
            }

            CompanyDriver::where('driving_status', 'idle')->where("plot_id", $plotId)
                ->chunk(100, function ($drivers) use ($booking) {
                    foreach ($drivers as $driver) {

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

            $plotData = CompanyPlot::where("id", $booking->pickup_plot_id)->first();
            $backupPlots = $plotData->backup_plots;
            if(isset($backupPlots) && $backupPlots != NULL){
                $currentIndex = array_search($plotId, $backupPlots);
                $plotId = $backupPlots[$currentIndex + 1] ?? null;
            }

            if(!isset($plotId) || $plotId == NULL){
                $dispatch_system_priority = CompanyDispatchSystem::where("dispatch_system", "bidding_fixed_fare_plot_base")->first();
                $dispatch_system = CompanyDispatchSystem::where("priority", (int) $dispatch_system_priority->priority + 1)->get();

                if(!isset($dispatch_system) || count($dispatch_system) <= 0){
                    return;
                }
                if($dispatch_system->first()->dispatch_system == "auto_dispatch_plot_base"){
                    AutoDispatchPlotJob::dispatch($booking->id, 0, $this->tenantDatabase);
                }
                elseif($dispatch_system->first()->dispatch_system == "auto_dispatch_nearest_driver"){
                    AutoDispatchNearestDriverJob::dispatch($booking->id, $this->tenantDatabase, []);
                }
                return;
            }
            
            if($this->count < 2){
                $dispatch_system_followup = CompanyDispatchSystem::where("dispatch_system", "bidding_fixed_fare_plot_base")->orderBy("sub_priority")->get();
                foreach($dispatch_system_followup as $i => $followup){
                    if($followup->steps == "immediately_show_on_dispatcher_panel"){
                        if($this->count == 0){
                            // event(new BookingShownOnDispatcher($booking));
                            $dispatchers = Dispatcher::where("status", "active")->orderBy("id", "DESC")->pluck("id");
                            Http::withHeaders([
                                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                            ])->post(env('NODE_SOCKET_URL') . '/send-notification-dispatcher', [
                                'dispatchers' => $dispatchers,
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
                                    'pickup_time' => $booking->pickup_time,
                                    'booking_date' => $booking->booking_date
                                ]
                            ]);
                            break;
                        }
                    }
                    elseif($followup->steps == "shows_up_after_first_rejection_or_wait_time_elapsed"){
                        if($this->count == 1){
                            // event(new BookingShownOnDispatcher($booking));
                            $dispatchers = Dispatcher::where("status", "active")->orderBy("id", "DESC")->pluck("id");
                            Http::withHeaders([
                                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                            ])->post(env('NODE_SOCKET_URL') . '/send-notification-dispatcher', [
                                'dispatchers' => $dispatchers,
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
                                    'pickup_time' => $booking->pickup_time,
                                    'booking_date' => $booking->booking_date
                                ]
                            ]);
                            break;
                        }
                    }
                }
            }

            $count = $this->count + 1;
            SendBiddingFixedFareNotificationJob::dispatch($booking->id, $plotId, $count, $this->tenantDatabase)
                ->delay(now()->addSeconds(90));
        }
        catch(\Exception $e){
            \Log::info("Bidding Fioxed Fare");
            \Log::info($e->getMessage());
        }
    }
}
