<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CompanyBooking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class CheckUpcomingRide extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-upcoming-ride';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        $tenants = DB::connection('central')
            ->table('tenants')
            ->get();

         foreach ($tenants as $tenant) {

            try {
                // 2️⃣ Switch tenant database
                $this->setTenantDatabase($tenant->id);

                $bookings = CompanyBooking::on('tenant')
                    ->whereDate('booking_date', Carbon::today())
                    ->where('booking_status', 'pending')
                    ->whereNotNull('driver')
                    ->where(function ($query) {
                        $query->where('dispatch_released', true)
                            ->orWhere('dispatch_released', 1);
                    })
                    ->whereTime(
                        'pickup_time',
                        '<=',
                        $now->copy()->subMinutes(5)->format('H:i:s')
                    )
                    ->get();

                    foreach($bookings as $booking){
                        \Log::info("upcoming ride is due; driver app must start it", [
                            'booking_id' => $booking->id,
                        ]);
                    }

                } catch (\Throwable $e) {
                        \Log::error('Tenant cron failed', [
                            'tenant' => $tenant->id ?? null,
                            'error'  => $e->getMessage()
                        ]);
                }
            \Log::info("complete cron");
         }
    }

    private function setTenantDatabase($database)
    {
        config(['database.connections.tenant.database' => "tenant".$database]);

        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');
    }
}
