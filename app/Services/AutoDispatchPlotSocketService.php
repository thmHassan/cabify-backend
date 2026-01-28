<?php

namespace App\Services;

use App\Models\CompanyBooking;
use App\Models\CompanyDriver;
use App\Models\CompanyPlot;
use App\Models\CompanyDispatchSystem;
use App\Models\Dispatchers;
use Illuminate\Support\Facades\Http;

class AutoDispatchPlotSocketService
{
    public static function dispatch(CompanyBooking $booking, int $priority = 0, ?int $plotId = null)
    {
        // Stop if booking is already assigned
        if(isset($booking->driver) && $booking->driver != null && $booking->driver != "") {
            return;
        }

        // Determine plot
        $plotId = $plotId ?? $booking->pickup_plot_id;

        // Pick driver for current plot and priority
        $driver = CompanyDriver::where('driving_status', 'idle')
            // ->whereNotNull("device_token")
            ->where('plot_id', $plotId)
            ->orderBy('priority_plot')
            ->skip($priority)
            ->take(1)
            ->first();

        // If no driver found, check backup plots
        if(!$driver) {
            $plotData = CompanyPlot::find($booking->pickup_plot_id);
            $backupPlots = array_map('intval', explode(',', $plotData->backup_plots));
            $currentIndex = array_search($plotId, $backupPlots);
            $nextPlotId = $backupPlots[$currentIndex + 1] ?? null;

            if(!$nextPlotId) {
                // No more plots, escalate to next dispatch system if needed
                $dispatch_system_priority = CompanyDispatchSystem::where("dispatch_system", "auto_dispatch_plot_base")->first();
                $dispatch_system = CompanyDispatchSystem::where("priority", (int) $dispatch_system_priority->priority + 1)->get();

                if($dispatch_system->first()->dispatch_system == "bidding_fixed_fare_plot_base"){
                    // You can dispatch your bidding job here if needed
                }
                elseif($dispatch_system->first()->dispatch_system == "auto_dispatch_nearest_driver"){
                    // Dispatch nearest driver job if needed
                }
                return;
            }

            // Retry with next plot
            return self::dispatch($booking, 0, $nextPlotId);
        }

        // 🔥 Send booking to Node Socket
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

        if($priority < 4){
            $dispatchers = Dispatcher::pluck("id")->toArray();
            $dispatch_system_followup = CompanyDispatchSystem::where("dispatch_system", "auto_dispatch_plot_base")->orderBy("sub_priority")->get();
    
            foreach($dispatch_system_followup as $i => $followup){
                if($followup->steps == "immediately_show_on_dispatcher_panel"){
                    if($priority == 0){
                        Http::withHeaders([
                            'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                        ])->post(env('NODE_SOCKET_URL') . '/send-new-booking', [
                            'dispatchers' => $dispatchers,
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

        // Schedule next attempt in 30s if driver hasn't accepted
        dispatch(function() use ($booking, $priority, $plotId) {
            sleep(30); // Wait 30s
            $bookingFresh = CompanyBooking::find($booking->id);
            self::dispatch($bookingFresh, $priority + 1, $plotId);
        });
    }
}
