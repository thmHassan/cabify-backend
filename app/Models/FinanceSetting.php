<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinanceSetting extends Model
{
    use HasFactory;

    protected $table = 'finance_settings';

    protected $fillable = [
        'account_driver_payout_timing',
        'cash_driver_collection_policy',
        'online_driver_payout_policy',
        'default_driver_commission_percent',
        'default_driver_commission_fixed',
        'stripe_fee_policy',
        'statement_prefix',
        'settlement_prefix',
    ];
}
