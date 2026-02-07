<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CompanyBooking;
use Carbon\Carbon;

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

        $bookings = CompanyBooking::where("booking_date", date("Y-m-d"))
                    ->where("booking_status", "pending")
                    ->whereTime('pickup_time', '<=', $now->subMinutes(5)->format('H:i:s'))
                    ->get();

        foreach($bookings as $booking){
            $booking->booking_status = "started";
            $booking->save();

            \Log::info("upcoming to current booking ". $booking->id);
        }
    }
}
