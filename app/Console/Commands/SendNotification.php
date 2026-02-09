<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FCMService;
use App\Models\CompanyNotification;
use App\Models\CompanyToken;

class SendNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-notification {title} {body} {users} {user_type}';

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
        $user_type = $this->argument('user_type');

        if(isset($users) && $users != NULL){
            foreach($users as $key => $user){

                if(isset($user_type) && $user_type == "users"){
                    $tokens = CompanyToken::where("user_id", $user->id)->where("user_type", "rider")->get();
                }
                else{
                    $tokens = CompanyToken::where("user_id", $user->id)->where("user_type", "driver")->get();
                }
                \Log::info("UserId for notification--------". $user->id);

                if(isset($tokens) && $tokens != NULL){
                    foreach($tokens as $key => $token){
                        FCMService::sendToDevice(
                            $token->fcm_token,
                            $title,
                            $body
                        );  
                    }
                }
                
                $record = new CompanyNotification;
                $record->user_type = (isset($user_type) && $user_type == "users") ? "rider" : 'driver';
                $record->user_id = $user->id;
                $record->title = $title;
                $record->message = $body;
                $record->save();
            }
        }
        \Log::info("notifiication cron completed successfully");
    }
}
