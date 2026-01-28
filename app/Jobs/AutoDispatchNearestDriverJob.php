<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\CompanyBooking;
use App\Models\CompanyDriver;
use App\Models\CompanyDispatchSystem;
use App\Models\CompanyPlot;
use App\Services\FCMService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Events\BookingShownOnDispatcher;

class AutoDispatchNearestDriverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $bookingId, public string $tenantDatabase, public array $driverIds = [])
    {
        //
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

        $plot = CompanyPlot::where("id", $booking->pickup_plot_id)->first();
        $plotData = json_decode($plot->features, true);

        // safety check
        if (
            !isset($plotData['geometry']['coordinates'])
        ) {
            return;
        }

        // coordinates is a JSON STRING → decode again
        $coordinates = $plotData['geometry']['coordinates'];

        if (is_string($coordinates)) {
            $coordinates = json_decode($coordinates, true);
        }

        // expecting [[[lng, lat], [lng, lat], ...]]
        if (
            !isset($coordinates[0]) ||
            !is_array($coordinates[0])
        ) {
            return;
        }

        $array = $coordinates[0];
        $points = collect($array)
                ->map(fn($p) => "{$p[0]} {$p[1]}")
                ->implode(',');

        $polygonWKT = "POLYGON(($points))";

        $driver = CompanyDriver::where('driving_status', 'idle')
                ->whereRaw(
                    "ST_Contains(
                        ST_GeomFromText(?),
                        POINT(drivers.longitude, drivers.latitude)
                    )",
                    [$polygonWKT]
                )
                // ->whereNotNull("device_token")
                ->whereNotIn("id", $this->driverIds)
                ->first();        

        if(!isset($driver) || $driver == NULL){
            $dispatch_system_priority = CompanyDispatchSystem::where("dispatch_system", "auto_dispatch_nearest_driver")->first();
            $dispatch_system = CompanyDispatchSystem::where("priority", (int) $dispatch_system_priority->priority + 1)->get();

            if(!isset($dispatch_system) || count($dispatch_system) <= 0){
                return;
            }
            if($dispatch_system->first()->dispatch_system == "auto_dispatch_plot_base"){
                AutoDispatchPlotJob::dispatch($booking->id, 0, $this->tenantDatabase);
            }
            elseif($dispatch_system->first()->dispatch_system == "bidding_fixed_fare_plot_base"){
                SendBiddingFixedFareNotificationJob::dispatch($booking->id, NULL, 0, $this->tenantDatabase);
            }
            return;
        }
        
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
        
        array_push($this->driverIds, $driver->id);

        if(count($this->driverIds) < 5){
            $dispatch_system_followup = CompanyDispatchSystem::where("dispatch_system", "auto_dispatch_nearest_driver")->orderBy("sub_priority")->get();
    
            foreach($dispatch_system_followup as $i => $followup){
                if($followup->steps == "immediately_show_on_dispatcher_panel"){
                    if(count($this->driverIds) == 1){
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
                elseif($followup->steps == "show_only_after_not_selected_in_auto_dispatch_first_try"){
                    if(count($this->driverIds) == 2){
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
                elseif($followup->steps == "show_only_after_not_selected_in_auto_dispatch_second_try"){
                    if(count($this->driverIds) == 3){
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
                elseif($followup->steps == "show_only_after_not_selected_in_auto_dispatch_third_try"){
                    if(count($this->driverIds) == 4){
                        event(new BookingShownOnDispatcher($booking));
                        break;
                    }
                }
            }
        }

        AutoDispatchNearestDriverJob::dispatch($booking->id, $this->tenantDatabase, $this->driverIds)
            ->delay(now()->addSeconds(30));
    }
}
