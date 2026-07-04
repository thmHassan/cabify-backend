<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingDispatchCycle extends Model
{
    protected $table = 'booking_dispatch_cycles';

    protected $fillable = [
        'booking_id',
        'primary_plot_id',
        'current_plot_id',
        'current_driver_id',
        'current_driver_rank',
        'status',
        'visited_plot_ids',
        'notified_driver_ids',
        'attempted_driver_ids',
        'rejected_driver_ids',
        'offer_token',
        'offer_expires_at',
        'fallback_to_bidding',
    ];

    protected $casts = [
        'visited_plot_ids' => 'array',
        'notified_driver_ids' => 'array',
        'attempted_driver_ids' => 'array',
        'rejected_driver_ids' => 'array',
        'offer_token' => 'integer',
        'offer_expires_at' => 'datetime',
        'fallback_to_bidding' => 'boolean',
    ];
}
