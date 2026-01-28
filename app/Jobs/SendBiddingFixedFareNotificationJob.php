<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\CompanyDriver;
use App\Models\CompanyBooking;
use App\Models\CompanyPlot;
use App\Services\FCMService;
use App\Models\CompanyDispatchSystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Events\BookingShownOnDispatcher;

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
        config([
            'database.connections.tenant.database' => "tenant".$this->tenantDatabase,
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');

        $booking = CompanyBooking::where("id",$this->bookingId)->first();

        if(isset($booking->driver) && $booking->driver != NULL && $booking->driver != ""){
            return;
        }

        $plotId = $this->plotId;
        if(!isset($plotId) || $plotId == NULL){
            $plotId = (int) $booking->pickup_plot_id;
        }

        CompanyDriver::where('driving_status', 'idle')->where("plot_id", $plotId)
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
                            'type' => 'auto_dispatch_plot'
                        ]
                    ]);
                }
            });

        $plotData = CompanyPlot::where("id", $booking->pickup_plot_id)->first();
        $backupPlots = array_map('intval', explode(',', $plotData->backup_plots));
        $currentIndex = array_search($plotId, $backupPlots);
        $plotId = $backupPlots[$currentIndex + 1] ?? null;

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
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
                elseif($followup->steps == "shows_up_after_first_rejection_or_wait_time_elapsed"){
                    if($this->count == 1){
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
            }
        }

        $count = $this->count + 1;
        SendBiddingFixedFareNotificationJob::dispatch($booking->id, $plotId, $count, $this->tenantDatabase)
            ->delay(now()->addSeconds(90));
    }
}
