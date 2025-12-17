<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FCMService;

class SendNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-notification {$title} {$body} {$users}';

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
        \Log::info("Notification cron started successfully");
        $title = $this->argument('title');
        $body = $this->argument('body');
        $users = $this->argument('users');

        foreach($users as $key => $user){
            \Log::info("UserId for notification--------". $user->id);
            FCMService::sendToDevice(
                $user->device_token,
                $title,
                $body
            );        
        }
        \Log::info("notifiication cron completed successfully");
    }
}
