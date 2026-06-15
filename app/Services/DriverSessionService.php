<?php

namespace App\Services;

use App\Models\CompanyDriver;
use App\Models\CompanyToken;

class DriverSessionService
{
    public static function invalidate(CompanyDriver $driver): void
    {
        $driver->auth_version = (int) ($driver->auth_version ?? 0) + 1;
        $driver->online_status = 'offline';
        $driver->device_token = null;
        $driver->fcm_token = null;
        $driver->save();

        CompanyToken::where('user_id', $driver->id)
            ->where('user_type', 'driver')
            ->delete();
    }
}
