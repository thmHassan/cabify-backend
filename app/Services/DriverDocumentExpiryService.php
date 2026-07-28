<?php

namespace App\Services;

use App\Models\CompanyDocumentType;
use App\Models\CompanyDriver;
use App\Models\DriverDocument;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DriverDocumentExpiryService
{
    public function complianceIssues(CompanyDriver $driver, ?CarbonInterface $today = null): Collection
    {
        $today ??= today();

        return $this->expiredDocumentIssues($driver, $today)
            ->concat($this->overdueMissingRequirementIssues($driver, $today))
            ->values();
    }

    public function syncRestriction(CompanyDriver $driver, ?CarbonInterface $today = null): Collection
    {
        $issues = $this->complianceIssues($driver, $today);

        if ($issues->isNotEmpty()) {
            $updates = [
                'document_expiry_blocked_at' => $driver->document_expiry_blocked_at ?: now(),
                'online_status' => 'offline',
            ];

            if (in_array(strtolower((string) $driver->status), ['accepted', 'approved', 'active'], true)) {
                $updates['status_before_document_expiry_block'] = $driver->status;
                $updates['status'] = 'blocked';
            }

            $driver->forceFill($updates)->save();

            return $issues;
        }

        if ($driver->document_expiry_blocked_at) {
            $updates = [
                'document_expiry_blocked_at' => null,
                'status_before_document_expiry_block' => null,
            ];

            if (
                strtolower((string) $driver->status) === 'blocked'
                && filled($driver->status_before_document_expiry_block)
            ) {
                $updates['status'] = $driver->status_before_document_expiry_block;
            }

            $driver->forceFill($updates)->save();
        }

        return collect();
    }

    public function restrictionPayload(Collection $issues): array
    {
        $hasMissingRequirement = $issues->contains(
            fn (array $issue) => $issue['issue_type'] === 'missing_required_document'
        );

        return [
            'account_restricted' => true,
            'restriction_reason' => $hasMissingRequirement
                ? 'required_document_missing'
                : 'document_expired',
            'contact_company' => true,
            'message' => $hasMissingRequirement
                ? 'A required driver document is missing or awaiting approval. Please upload it and contact your company for approval.'
                : 'Your driver document has expired. Please upload a renewed document and contact your company for approval.',
            'expired_documents' => $issues
                ->where('issue_type', 'expired_document')
                ->values()
                ->all(),
            'missing_documents' => $issues
                ->where('issue_type', 'missing_required_document')
                ->values()
                ->all(),
            'document_compliance_issues' => $issues->values()->all(),
        ];
    }

    public function latestVerifiedExpiryDocuments(CompanyDriver $driver): Collection
    {
        return DriverDocument::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'verified')
            ->whereNotNull('has_expiry_date')
            ->whereHas('documentDetail', fn ($query) => $query->where('has_expiry_date', 'yes'))
            ->orderByDesc('id')
            ->get()
            ->unique(fn (DriverDocument $document) => $document->document_id ?: $document->document_name)
            ->values();
    }

    public function missingExpiryRequirements(CompanyDriver $driver): Collection
    {
        $verifiedDocumentIds = DriverDocument::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'verified')
            ->whereNotNull('has_expiry_date')
            ->pluck('document_id')
            ->filter()
            ->unique();

        return CompanyDocumentType::query()
            ->where('has_expiry_date', 'yes')
            ->whereNotNull('expiry_requirement_enabled_at')
            ->whereNotIn('id', $verifiedDocumentIds)
            ->get();
    }

    public function graceDeadline(CompanyDriver $driver, CompanyDocumentType $requirement): Carbon
    {
        $enabledAt = Carbon::parse($requirement->expiry_requirement_enabled_at)->startOfDay();
        $driverCreatedAt = $driver->created_at ? Carbon::parse($driver->created_at)->startOfDay() : null;
        $isExistingDriver = !$driverCreatedAt || $driverCreatedAt->lt($enabledAt);

        if (!$isExistingDriver) {
            return $driverCreatedAt;
        }

        return $enabledAt->copy()->addDays((int) ($requirement->expiry_grace_days ?? 7));
    }

    private function expiredDocumentIssues(CompanyDriver $driver, CarbonInterface $today): Collection
    {
        return $this->latestVerifiedExpiryDocuments($driver)
            ->filter(fn (DriverDocument $document) => $document->has_expiry_date < $today->toDateString())
            ->map(fn (DriverDocument $document) => [
                'issue_type' => 'expired_document',
                'document_record_id' => $document->id,
                'document_id' => $document->document_id,
                'document_name' => $document->document_name,
                'expiry_date' => $document->has_expiry_date,
            ]);
    }

    private function overdueMissingRequirementIssues(
        CompanyDriver $driver,
        CarbonInterface $today
    ): Collection {
        return $this->missingExpiryRequirements($driver)
            ->filter(fn (CompanyDocumentType $requirement) => $today->isAfter(
                $this->graceDeadline($driver, $requirement)
            ))
            ->map(fn (CompanyDocumentType $requirement) => [
                'issue_type' => 'missing_required_document',
                'document_id' => $requirement->id,
                'document_name' => $requirement->document_name,
                'grace_expires_at' => $this->graceDeadline($driver, $requirement)->toDateString(),
            ]);
    }
}
