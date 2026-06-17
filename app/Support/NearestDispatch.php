<?php

namespace App\Support;

class NearestDispatch
{
    public const ACTIVE_PREFIX = 'NEAREST_DISPATCH_ACTIVE|';

    public static function isActiveOffer(?string $dispatcherAction): bool
    {
        return is_string($dispatcherAction) && str_starts_with($dispatcherAction, self::ACTIVE_PREFIX);
    }
}
