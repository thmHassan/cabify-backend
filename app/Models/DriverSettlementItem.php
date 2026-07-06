<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverSettlementItem extends Model
{
    use HasFactory;

    protected $table = 'driver_settlement_items';

    protected $fillable = [
        'driver_settlement_id',
        'booking_id',
        'driver_package_id',
        'item_date',
        'item_type',
        'gross_amount',
        'commission_amount',
        'company_owes_driver',
        'driver_owes_company',
        'net_amount',
        'payment_channel',
        'snapshot',
    ];

    protected $casts = [
        'item_date' => 'date',
        'snapshot' => 'array',
    ];

    public function settlement()
    {
        return $this->belongsTo(DriverSettlement::class, 'driver_settlement_id');
    }
}
