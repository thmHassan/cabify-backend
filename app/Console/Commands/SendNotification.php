<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FCMService;
use App\Models\CompanyNotification;
use App\Models\CompanyToken;
use App\Models\CompanyUser;
use App\Models\CompanyDriver;

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
    protected $description = 'Send push notifications to users or drivers';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        \Log::info('Notification cron started successfully');

        $title = $this->argument('title');
        $body = $this->argument('body');
        $userIds = $this->parseUserIds($this->argument('users'));
        $userType = $this->argument('user_type');

        if (empty($userIds)) {
            \Log::warning('Notification cron skipped: no recipient IDs provided');
            return;
        }

        $users = $userType === 'users'
            ? CompanyUser::whereIn('id', $userIds)->get()
            : CompanyDriver::whereIn('id', $userIds)->get();

        foreach ($users as $user) {
            if ($userType === 'users') {
                $tokens = CompanyToken::where('user_id', $user->id)->where('user_type', 'rider')->get();
            } else {
                $tokens = CompanyToken::where('user_id', $user->id)->where('user_type', 'driver')->get();
            }

            \Log::info('UserId for notification--------' . $user->id);

            if ($tokens->isNotEmpty()) {
                foreach ($tokens as $token) {
                    FCMService::sendToDevice(
                        $token->fcm_token,
                        $title,
                        $body
                    );
                }
            }

            $record = new CompanyNotification;
            $record->user_type = $userType === 'users' ? 'rider' : 'driver';
            $record->user_id = $user->id;
            $record->title = $title;
            $record->message = $body;
            $record->save();
        }

        \Log::info('notifiication cron completed successfully');
    }

    /**
     * @return int[]
     */
    private function parseUserIds(string $users): array
    {
        if ($users === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', explode(',', $users)),
            fn (int $id) => $id > 0
        )));
    }
}
