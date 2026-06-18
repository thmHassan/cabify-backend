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
        'status',
        'visited_plot_ids',
        'notified_driver_ids',
        'offer_token',
    ];

    protected $casts = [
        'visited_plot_ids' => 'array',
        'notified_driver_ids' => 'array',
        'offer_token' => 'integer',
    ];
}
