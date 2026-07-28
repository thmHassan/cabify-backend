<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppMaintenanceSetting extends Model
{
    use HasFactory;

    public const DEFAULT_MESSAGE = 'Now this app is under maintenance';

    protected $connection = 'central';

    protected $table = 'app_maintenance_settings';

    protected $fillable = [
        'driver_app',
        'customer_app',
        'message',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'driver_app' => 'enable',
            'customer_app' => 'enable',
            'message' => static::DEFAULT_MESSAGE,
        ]);
    }

    public function statusFor(string $app): array
    {
        $app = strtolower(trim($app));
        $column = in_array($app, ['driver', 'driver_app'], true) ? 'driver_app' : 'customer_app';
        $appName = $column === 'driver_app' ? 'driver' : 'customer';
        $now = now();

        $withinSchedule = (!$this->starts_at || $this->starts_at->lte($now))
            && (!$this->ends_at || $this->ends_at->gte($now));

        $maintenance = $this->{$column} === 'disable' && $withinSchedule;

        return [
            'app' => $appName,
            'status' => $this->{$column},
            'maintenance' => $maintenance,
            'message' => $maintenance ? ($this->message ?: static::DEFAULT_MESSAGE) : null,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
        ];
    }
}
