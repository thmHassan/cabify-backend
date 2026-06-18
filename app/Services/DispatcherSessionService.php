<?php

namespace App\Services;

use App\Models\Dispatcher;

class DispatcherSessionService
{
    public static function invalidateAll(): int
    {
        return Dispatcher::query()->increment('auth_version');
    }
}
