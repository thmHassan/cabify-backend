<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CompanyDriver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MakeCancelRideZero extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:make-cancel-ride-zero';

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
       $tenants = DB::connection('central')
            ->table('tenants')
            ->get();

         foreach ($tenants as $tenant) {

            try {
                // 2️⃣ Switch tenant database
                $this->setTenantDatabase($tenant->id);

                $drivers = CompanyDriver::orderBy("id", "DESC")->get();

                foreach($drivers as $driver){
                    $driver->cancel_rides_per_day = 0;
                    $driver->save();
                }

            } catch (\Throwable $e) {
                \Log::error('Tenant cron failed', [
                    'tenant' => $tenant->database,
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
    }
}
