<?php

namespace App\Services;

use App\Models\CompanyDispatchSystem;
use Illuminate\Support\Facades\Schema;

class DispatchContextService
{
    public function resolve(?string $companyBookingSystem = null): array
    {
        $enabledSystems = [];
        $fallbackToBidding = false;

        if (Schema::connection('tenant')->hasTable('dispatch_system')) {
            $enabledSystems = $this->query()->where('status', 'enable')
                ->orderByRaw('priority IS NULL, priority ASC')
                ->orderBy('id', 'ASC')
                ->pluck('dispatch_system')
                ->values()
                ->all();

            $fallbackToBidding = $this->query()->where('status', 'enable')
                ->where('steps', 'put_in_bidding_panel')
                ->when($enabledSystems[0] ?? null, function ($query, $dispatchSystem) {
                    $query->where('dispatch_system', $dispatchSystem);
                })
                ->exists();
        }

        $dispatchSystem = $enabledSystems[0] ?? null;
        if (!$dispatchSystem) {
            $dispatchSystem = $companyBookingSystem === 'bidding'
                ? 'bidding'
                : 'auto_dispatch_plot_base';
        }

        $biddingSystems = [
            'bidding',
            'bidding_fixed_fare_plot_base',
            'bidding_fixed_fare_nearest_driver',
        ];
        $supportsBidding = in_array($dispatchSystem, $biddingSystems, true);

        if (!$supportsBidding && Schema::connection('tenant')->hasTable('dispatch_system')) {
            $supportsBidding = $this->query()->where('status', 'enable')
                ->where(function ($query) use ($biddingSystems) {
                    $query->whereIn('dispatch_system', $biddingSystems)
                        ->orWhere(function ($query) {
                            $query->where('dispatch_system', 'auto_dispatch_nearest_driver')
                                ->where('steps', 'put_in_bidding_panel');
                        });
                })
                ->exists();
        }

        return [
            'dispatch_system' => $dispatchSystem,
            'supports_rank' => $dispatchSystem === 'auto_dispatch_plot_base',
            'supports_bidding' => $supportsBidding,
            'fallback_to_bidding' => $fallbackToBidding,
            'supports_manual_assignment' => true,
            'allow_asap' => $dispatchSystem !== 'manual_dispatch_only',
            'allow_scheduled' => true,
        ];
    }

    private function query()
    {
        return (new CompanyDispatchSystem())
            ->setConnection('tenant')
            ->newQuery();
    }
}
