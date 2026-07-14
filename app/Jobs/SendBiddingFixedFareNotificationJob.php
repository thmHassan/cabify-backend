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
use App\Events\BookingShownOnDispatcher;
use App\Models\CompanySendNewRide;
use App\Models\Dispatcher;
use App\Support\TenantDatabaseConfigurator;
use App\Support\VehicleDispatchFilter;

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
            $tenantConfigured = TenantDatabaseConfigurator::configure($this->tenantDatabase);
            if (!$tenantConfigured['configured']) {
                \Log::warning('Bidding fixed fare tenant configuration failed', [
                    'tenant' => $this->tenantDatabase,
                    'error' => $tenantConfigured['error'] ?? null,
                ]);
                return;
            }
            $tenantDbName = TenantDatabaseConfigurator::resolveSchemaName($this->tenantDatabase) ?? $this->tenantDatabase;

            $booking = CompanyBooking::where("id",$this->bookingId)->with('userDetail')->first();
            if (!$booking) {
                return;
            }

            if(isset($booking->driver) && $booking->driver != NULL && $booking->driver != ""){
                return;
            }

            if (!$booking->bidding_fallback || $booking->booking_system !== 'bidding_fixed_fare_plot_base') {
                $booking->bidding_fallback = true;
                $booking->booking_system = 'bidding_fixed_fare_plot_base';
                $booking->save();
            }

            $plotId = $this->plotId;
            if(!isset($plotId) || $plotId == NULL){
                $plotId = (int) $booking->pickup_plot_id;
            }

            $pickup_time = null;
            $booking_date = null;
            $pickupTimeValue = strtolower(trim((string) $booking->pickup_time));

            if ($pickupTimeValue !== '' && $pickupTimeValue !== 'asap' && $booking->booking_date) {
                try {
                    $bookingDateTime = \Carbon\Carbon::parse(
                        $booking->booking_date . ' ' . $booking->pickup_time
                    );

                    if ($bookingDateTime->greaterThan(now())) {
                        $pickup_time = $booking->pickup_time;
                        $booking_date = $booking->booking_date;
                    }
                } catch (\Exception $e) {
                    \Log::warning('Bidding fixed fare pickup datetime parse skipped', [
                        'booking_id' => $booking->id,
                        'booking_date' => $booking->booking_date,
                        'pickup_time' => $booking->pickup_time,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $fixedFarePayload = [
                'id' => $booking->id,
                'booking_id' => $booking->booking_id,
                'pickup_point' => $booking->pickup_point,
                'destination_point' => $booking->destination_point,
                'offered_amount' => $booking->offered_amount,
                'distance' => $booking->distance,
                'user_id' => $booking->user_id,
                'user_name' => $booking->name,
                'name' => $booking->name,
                'user_profile' => $booking->userDetail?->profile_image,
                'pickup_location' => $booking->pickup_location,
                'destination_location' => $booking->destination_location,
                'note' => $booking->note,
                'pickup_time' => (isset($pickup_time) && $pickup_time != NULL) ? $pickup_time : NULL,
                'booking_date' => (isset($booking_date) && $booking_date != NULL) ? $booking_date : NULL,
                'booking_system' => 'bidding_fixed_fare_plot_base',
                'bidding_fallback' => true,
                'fixed_fare' => true,
                'assignment_type' => 'fixed_fare_bidding',
                'pickup_plot_id' => $plotId,
            ];

            $notifiedDriverCount = 0;

            CompanyDriver::whereIn('status', ['accepted', 'approved', 'active'])
                ->where('driving_status', 'idle')
                ->where('online_status', 'online')
                ->where("plot_id", $plotId)
                ->when(
                    VehicleDispatchFilter::bookingRequiresSpecificVehicle($booking),
                    fn ($query) => VehicleDispatchFilter::scopeDriversForBooking($query, $booking)
                )
                ->chunk(100, function ($drivers) use ($booking, $fixedFarePayload, $tenantDbName, &$notifiedDriverCount) {
                    foreach ($drivers as $driver) {

                        $response = Http::withHeaders([
                            'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                            'database' => $tenantDbName,
                            'x-database' => $tenantDbName,
                        ])->timeout(10)->post(rtrim((string) config('services.node_socket.url'), '/') . '/send-new-ride', [
                            'drivers' => [$driver->id],
                            'tenantDb' => $tenantDbName,
                            'booking' => $fixedFarePayload,
                        ]);
                        $notifiedDriverCount++;

                        if (!$response->successful()) {
                            \Log::warning('Bidding fixed fare socket send failed', [
                                'booking_id' => $booking->id,
                                'driver_id' => $driver->id,
                                'tenant' => $tenantDbName,
                                'status' => $response->status(),
                                'body' => $response->body(),
                            ]);
                        } else {
                            \Log::info('Bidding fixed fare socket send response', [
                                'booking_id' => $booking->id,
                                'driver_id' => $driver->id,
                                'tenant' => $tenantDbName,
                                'response' => $response->json(),
                            ]);
                        }

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

            if ($notifiedDriverCount === 0) {
                \Log::warning('Bidding fixed fare found no eligible drivers for plot', [
                    'booking_id' => $booking->id,
                    'plot_id' => $plotId,
                    'tenant' => $tenantDbName,
                ]);
            }

            $currentPlotId = $plotId;
            $plotId = NULL;
            $plotData = CompanyPlot::where("id", $booking->pickup_plot_id)->first();
            $backupPlots = $plotData?->backup_plots;
            if(isset($backupPlots) && $backupPlots != NULL){
                if ((string) $currentPlotId === (string) $booking->pickup_plot_id) {
                    $plotId = $backupPlots[0] ?? null;
                } else {
                    $currentIndex = array_search($currentPlotId, $backupPlots);
                    $plotId = $currentIndex === false ? null : ($backupPlots[$currentIndex + 1] ?? null);
                }
            }

            if(!isset($plotId) || $plotId == NULL){
                $dispatch_system_priority = CompanyDispatchSystem::where("dispatch_system", "bidding_fixed_fare_plot_base")->first();
                $dispatch_system = CompanyDispatchSystem::where("status", "enable")->where("priority", (int) $dispatch_system_priority->priority + 1)->get();

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
                                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                                'database' => $tenantDbName,
                                'x-database' => $tenantDbName,
                            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/send-notification-dispatcher', [
                                'dispatchers' => $dispatchers,
                                'tenantDb' => $tenantDbName,
                                'booking' => [
                                    'id' => $booking->id,
                                    'booking_id' => $booking->booking_id,
                                    'pickup_point' => $booking->pickup_point,
                                    'destination_point' => $booking->destination_point,
                                    'offered_amount' => $booking->offered_amount,
                                    'distance' => $booking->distance,
                                    'user_id' => $booking->user_id,
                                    'user_name' => $booking->name,
                                    'user_profile' => $booking->userDetail?->profile_image,
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
                                'Authorization' => 'Bearer ' . config('services.node_socket.internal_secret'),
                                'database' => $tenantDbName,
                                'x-database' => $tenantDbName,
                            ])->post(rtrim((string) config('services.node_socket.url'), '/') . '/send-notification-dispatcher', [
                                'dispatchers' => $dispatchers,
                                'tenantDb' => $tenantDbName,
                                'booking' => [
                                    'id' => $booking->id,
                                    'booking_id' => $booking->booking_id,
                                    'pickup_point' => $booking->pickup_point,
                                    'destination_point' => $booking->destination_point,
                                    'offered_amount' => $booking->offered_amount,
                                    'distance' => $booking->distance,
                                    'user_id' => $booking->user_id,
                                    'user_name' => $booking->name,
                                    'user_profile' => $booking->userDetail?->profile_image,
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
            \Log::error('Bidding fixed fare notification job failed', [
                'booking_id' => $this->bookingId,
                'plot_id' => $this->plotId,
                'count' => $this->count,
                'tenant' => $this->tenantDatabase,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
