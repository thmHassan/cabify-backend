<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Setting;

class MailConfigurationService
{
    /**
     * Pick the mailer and apply SMTP settings when needed.
     * Prefer ZeptoMail when configured in .env (MAIL_MAILER=zeptomail + token).
     */
    public static function resolveMailer(?CompanySetting $tenantSettings = null): string
    {
        $zeptomailToken = config('mail.mailers.zeptomail.token');

        if (config('mail.default') === 'zeptomail' && filled($zeptomailToken)) {
            return 'zeptomail';
        }

        if (
            $tenantSettings
            && ($tenantSettings->map_settings ?? 'default') !== 'default'
            && filled($tenantSettings->mail_server)
        ) {
            static::applySmtpConfig(
                $tenantSettings->mail_server,
                (int) ($tenantSettings->mail_port ?? 587),
                $tenantSettings->mail_user_name,
                $tenantSettings->mail_password,
                $tenantSettings->mail_from,
                $tenantSettings->mail_user_name
            );

            return 'smtp';
        }

        $central = Setting::on('central')->orderByDesc('id')->first();

        if ($central && filled($central->smtp_host)) {
            static::applySmtpConfig(
                $central->smtp_host,
                587,
                $central->smtp_user_name,
                $central->smtp_password,
                $central->smtp_from_address,
                $central->smtp_user_name
            );

            return 'smtp';
        }

        if (filled($zeptomailToken)) {
            return 'zeptomail';
        }

        return config('mail.default', 'smtp');
    }

    private static function applySmtpConfig(
        ?string $host,
        int $port,
        ?string $username,
        ?string $password,
        ?string $fromAddress,
        ?string $fromName
    ): void {
        config([
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $password,
            'mail.from.address' => $fromAddress ?: config('mail.from.address', 'noreply@cabifyit.com'),
            'mail.from.name' => $fromName ?: config('mail.from.name', 'CabifyIT'),
        ]);
    }
}
