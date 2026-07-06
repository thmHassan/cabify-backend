<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountStatementItem extends Model
{
    use HasFactory;

    protected $table = 'account_statement_items';

    protected $fillable = [
        'account_statement_id',
        'booking_id',
        'booking_date',
        'pickup_point',
        'destination_point',
        'driver_name',
        'fare_amount',
        'extra_amount',
        'total_amount',
        'payment_channel',
        'booking_status',
        'snapshot',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'snapshot' => 'array',
    ];

    public function statement()
    {
        return $this->belongsTo(AccountStatement::class, 'account_statement_id');
    }
}
