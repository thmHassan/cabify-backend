<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountStatement extends Model
{
    use HasFactory;

    protected $table = 'account_statements';

    protected $fillable = [
        'statement_number',
        'account_id',
        'period_start',
        'period_end',
        'subtotal',
        'adjustment_amount',
        'total_amount',
        'paid_amount',
        'balance_amount',
        'currency',
        'status',
        'sent_at',
        'collected_at',
        'notes',
        'meta',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'sent_at' => 'datetime',
        'collected_at' => 'datetime',
        'meta' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(AccountStatementItem::class, 'account_statement_id');
    }

    public function account()
    {
        return $this->hasOne(CompanyAccount::class, 'id', 'account_id');
    }
}
