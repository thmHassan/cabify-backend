<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $connection = 'central';

    protected $fillable = ['code', 'name', 'symbol', 'decimal_places', 'symbol_position', 'is_active', 'exchange_enabled', 'stripe_enabled', 'sort_order'];

    protected $casts = [
        'decimal_places' => 'integer',
        'is_active' => 'boolean',
        'exchange_enabled' => 'boolean',
        'stripe_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
