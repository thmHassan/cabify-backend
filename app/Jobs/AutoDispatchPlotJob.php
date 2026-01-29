<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\CompanyBooking;
use App\Models\CompanyDriver;
use App\Models\CompanyPlot;
use App\Jobs\AutoDispatchRetryJob;
use App\Events\BookingShownOnDispatcher;
use App\Models\CompanyDispatchSystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Services\FCMService;

class AutoDispatchPlotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $bookingId, public int $priority, public string $tenantDatabase, public ?int $plotId = NULL)
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

        $bookingId = $this->bookingId;
        $booking = CompanyBooking::where("id",$bookingId)->with('userDetail')->first();

        if(isset($booking->driver) && $booking->driver != NULL && $booking->driver != ""){
            return;
        }
        $plotId = $this->plotId;
        if(!isset($this->plotId) || $this->plotId == NULL){
            $plotId = (int) $booking->pickup_plot_id;
        }

        $priority = $this->priority;
        $driver = CompanyDriver::where('driving_status', 'idle')
                ->where('plot_id', $plotId)
                // ->where('device_token', '!=', '')
                ->orderBy("priority_plot", "ASC")
                ->skip($priority)
                ->take(1)
                ->first();

        \Log::info($priority);
        \Log::info("plot id");
        \Log::info($this->tenantDatabase);
        \Log::info($plotId);
        \Log::info($driver);

        if(!isset($driver) || $driver == NULL){
            \Log::info("driver not fount");
            $plotData = CompanyPlot::where("id", $booking->pickup_plot_id)->first();
            $backupPlots = array_map('intval', explode(',', $plotData->backup_plots));
            $currentIndex = array_search($plotId, $backupPlots);
            $plotId = $backupPlots[$currentIndex + 1] ?? null;
            $priority = 0;

            if(!isset($plotId) || $plotId == NULL || $plotId == ""){
                \Log::info("new plot not found");
                $dispatch_system_priority = CompanyDispatchSystem::where("dispatch_system", "auto_dispatch_plot_base")->first();
                $dispatch_system = CompanyDispatchSystem::where("priority", (int) $dispatch_system_priority->priority + 1)->get();

                if(!isset($dispatch_system) || count($dispatch_system) <= 0){
                    return;
                }
                if($dispatch_system->first()->dispatch_system == "bidding_fixed_fare_plot_base"){
                    SendBiddingFixedFareNotificationJob::dispatch($booking->id, NULL, 0, $this->tenantDatabase);
                }
                elseif($dispatch_system->first()->dispatch_system == "auto_dispatch_nearest_driver"){
                    AutoDispatchNearestDriverJob::dispatch($booking->id, $this->tenantDatabase, []);
                }
                return;
            }

            $driver = CompanyDriver::where('driving_status', 'idle')
                ->where('plot_id', $plotId)
                // ->whereNotNull("device_token")
                ->orderBy("priority_plot")
                ->skip($priority)
                ->take(1)
                ->first();
        }

        if($priority < 4){
            $dispatch_system_followup = CompanyDispatchSystem::where("dispatch_system", "auto_dispatch_plot_base")->orderBy("sub_priority")->get();
    
            foreach($dispatch_system_followup as $i => $followup){
                if($followup->steps == "immediately_show_on_dispatcher_panel"){
                    if($priority == 0){
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
                elseif($followup->steps == "show_only_after_not_selected_in_auto_dispatch_first_try"){
                    if($priority == 1){
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
                elseif($followup->steps == "show_only_after_not_selected_in_auto_dispatch_second_try"){
                    if($priority == 2){
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
                elseif($followup->steps == "show_only_after_not_selected_in_auto_dispatch_third_try"){
                    if($priority == 3){
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
            }
        }
        
        // FCMService::sendToDevice(
        //     $driver->device_token,
        //     'New Ride Available for Bidding 🚖',
        //     'Place your bid now',
        //     [
        //         'booking_id' => $booking->id,
        //     ]
        // );

        $response = Http::withHeaders([
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
        \Log::info($response->status());
        \Log::info($response->json());    
        \Log::info("Driver Id");
        \Log::info($driver->id);

        AutoDispatchPlotJob::dispatch($booking->id, $priority + 1, $this->tenantDatabase, $plotId)
            ->delay(now()->addSeconds(30));
    }
}
