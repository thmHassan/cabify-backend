<?php

namespace App\Console\Commands;

use App\Models\CompanyDriver;
use App\Models\CompanySetting;
use App\Models\CompanyDocumentType;
use App\Models\DriverDocument;
use App\Services\DriverDocumentExpiryService;
use App\Services\MailConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class ProcessDriverDocumentExpiries extends Command
{
    protected $signature = 'app:process-driver-document-expiries';

    protected $description = 'Warn drivers two days before document expiry and restrict expired accounts.';

    public function handle(DriverDocumentExpiryService $expiryService): int
    {
        $tenants = DB::connection('central')->table('tenants')->get(['id', 'data']);
        $reminders = 0;
        $restricted = 0;

        foreach ($tenants as $tenant) {
            try {
                $this->setTenantDatabase((string) $tenant->id);

                if (!$this->hasExpiryTrackingColumns()) {
                    continue;
                }

                $tenantData = json_decode((string) $tenant->data, true) ?: [];
                [$tenantReminders, $tenantRestricted] = $this->processCurrentTenant(
                    $expiryService,
                    $tenantData
                );
                $reminders += $tenantReminders;
                $restricted += $tenantRestricted;
            } catch (\Throwable $e) {
                \Log::error('Driver document expiry processing failed', [
                    'tenant' => $tenant->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$reminders} reminder(s); restricted {$restricted} driver(s).");

        return self::SUCCESS;
    }

    public function processCurrentTenant(
        DriverDocumentExpiryService $expiryService,
        array $tenantData = []
    ): array {
        $reminders = 0;
        $restricted = 0;
        $reminderDate = today()->addDays(2)->toDateString();
        $settings = CompanySetting::orderByDesc('id')->first();
        $mailer = MailConfigurationService::resolveMailer($settings);

        CompanyDriver::query()->orderBy('id')->chunkById(100, function ($drivers) use (
            $expiryService,
            $tenantData,
            $reminderDate,
            $mailer,
            &$reminders,
            &$restricted
        ) {
            foreach ($drivers as $driver) {
                $documents = $expiryService->latestVerifiedExpiryDocuments($driver);

                foreach ($documents->where('has_expiry_date', $reminderDate) as $document) {
                    if ($document->expiry_reminder_sent_at || !filled($driver->email)) {
                        continue;
                    }

                    try {
                        $this->sendReminder($mailer, $driver, $document, $tenantData);
                        $document->forceFill(['expiry_reminder_sent_at' => now()])->save();
                        $reminders++;
                    } catch (\Throwable $e) {
                        \Log::error('Driver document expiry reminder email failed', [
                            'driver_id' => $driver->id,
                            'document_id' => $document->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                foreach ($expiryService->missingExpiryRequirements($driver) as $requirement) {
                    $graceDeadline = $expiryService->graceDeadline($driver, $requirement);
                    $notice = DB::table('driver_document_compliance_notices')
                        ->where('driver_id', $driver->id)
                        ->where('document_id', $requirement->id)
                        ->first();

                    if (!$notice) {
                        DB::table('driver_document_compliance_notices')->insert([
                            'driver_id' => $driver->id,
                            'document_id' => $requirement->id,
                            'grace_expires_at' => $graceDeadline->toDateString(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    if ((!$notice || !$notice->notified_at) && filled($driver->email)) {
                        try {
                            $this->sendNewRequirementNotice(
                                $mailer,
                                $driver,
                                $requirement,
                                $graceDeadline->toDateString(),
                                $tenantData
                            );
                            DB::table('driver_document_compliance_notices')
                                ->where('driver_id', $driver->id)
                                ->where('document_id', $requirement->id)
                                ->update([
                                    'notified_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            $reminders++;
                        } catch (\Throwable $e) {
                            \Log::error('New driver document requirement email failed', [
                                'driver_id' => $driver->id,
                                'document_id' => $requirement->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                $wasRestricted = (bool) $driver->document_expiry_blocked_at;
                $expired = $expiryService->syncRestriction($driver);
                if (!$wasRestricted && $expired->isNotEmpty()) {
                    $restricted++;
                }
            }
        });

        return [$reminders, $restricted];
    }

    private function sendReminder(
        string $mailer,
        CompanyDriver $driver,
        DriverDocument $document,
        array $tenantData
    ): void {
        Mail::mailer($mailer)->send('emails.driver-document-expiry-reminder', [
            'driver' => $driver,
            'document' => $document,
            'companyName' => $tenantData['company_name'] ?? config('app.name'),
            'companyEmail' => $tenantData['email'] ?? null,
            'companyPhone' => $tenantData['phone'] ?? null,
        ], function ($message) use ($driver, $document) {
            $message->to($driver->email)
                ->subject("Action required: {$document->document_name} expires in 2 days");
        });
    }

    private function sendNewRequirementNotice(
        string $mailer,
        CompanyDriver $driver,
        CompanyDocumentType $requirement,
        string $graceDeadline,
        array $tenantData
    ): void {
        Mail::mailer($mailer)->send('emails.driver-document-required', [
            'driver' => $driver,
            'requirement' => $requirement,
            'graceDeadline' => $graceDeadline,
            'companyName' => $tenantData['company_name'] ?? config('app.name'),
            'companyEmail' => $tenantData['email'] ?? null,
            'companyPhone' => $tenantData['phone'] ?? null,
        ], function ($message) use ($driver, $requirement) {
            $message->to($driver->email)
                ->subject("Action required: upload {$requirement->document_name}");
        });
    }

    private function setTenantDatabase(string $database): void
    {
        config(['database.connections.tenant.database' => 'tenant' . $database]);
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');
    }

    private function hasExpiryTrackingColumns(): bool
    {
        return Schema::connection('tenant')->hasColumn('drivers_documents', 'expiry_reminder_sent_at')
            && Schema::connection('tenant')->hasColumn('drivers', 'document_expiry_blocked_at')
            && Schema::connection('tenant')->hasColumn('documents', 'expiry_requirement_enabled_at')
            && Schema::connection('tenant')->hasTable('driver_document_compliance_notices');
    }
}
