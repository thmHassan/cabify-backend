<?php

namespace App\Jobs;

use App\Models\CompanyRider;
use App\Models\CompanySetting;
use App\Services\MailConfigurationService;
use App\Support\TenantDatabaseConfigurator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendRiderRegistrationOtpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public int $riderId,
        public string $tenantDatabase
    ) {
    }

    public function handle(): void
    {
        $configured = TenantDatabaseConfigurator::configure($this->tenantDatabase);
        if (!$configured['configured']) {
            throw new \RuntimeException($configured['error'] ?? 'Unable to configure tenant database.');
        }

        $user = CompanyRider::find($this->riderId);
        if (!$user || !$user->email || !$user->otp) {
            return;
        }

        $settingData = CompanySetting::orderByDesc('id')->first();
        $mailer = MailConfigurationService::resolveMailer($settingData);

        config(['mail.mailers.smtp.timeout' => 10]);

        Mail::mailer($mailer)->send('emails.send-otp', [
            'name' => $user->name ?? 'User',
            'otp' => $user->otp,
        ], function ($message) use ($user) {
            $message->to($user->email)
                ->subject('Registration OTP');
        });
    }
}
