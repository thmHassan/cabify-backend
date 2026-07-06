<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancePayment extends Model
{
    use HasFactory;

    protected $table = 'finance_payments';

    protected $fillable = [
        'payment_number',
        'payer_type',
        'payer_id',
        'receiver_type',
        'receiver_id',
        'channel',
        'amount',
        'currency',
        'payment_date',
        'reference',
        'notes',
        'status',
        'account_statement_id',
        'driver_settlement_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'payment_date' => 'date',
    ];
}
