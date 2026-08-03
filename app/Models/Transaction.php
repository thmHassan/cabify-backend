<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $table = "transactions";

    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'base_amount',
        'base_currency',
        'exchange_rate',
        'exchange_rate_at',
        'rate_provider',
        'external_reference',
        'paid_at',
        'status',
        'method',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'base_amount' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'exchange_rate_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function companyDetail(){
        return $this->hasOne(Tenant::class, 'id', 'user_id');
    }
}
