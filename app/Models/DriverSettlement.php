<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverSettlement extends Model
{
    use HasFactory;

    protected $table = 'driver_settlements';

    protected $fillable = [
        'settlement_number',
        'driver_id',
        'period_start',
        'period_end',
        'gross_fares',
        'company_owes_driver',
        'driver_owes_company',
        'adjustment_amount',
        'net_amount',
        'paid_amount',
        'currency',
        'status',
        'settled_at',
        'notes',
        'meta',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'settled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(DriverSettlementItem::class, 'driver_settlement_id');
    }

    public function driver()
    {
        return $this->hasOne(CompanyDriver::class, 'id', 'driver_id');
    }
}
