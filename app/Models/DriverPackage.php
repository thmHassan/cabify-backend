<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverPackage extends Model
{
    use HasFactory;

    protected $table = "driver_packages";

     protected $fillable = [
        'driver_id',
        'package_type',
        'pending_rides',
        'start_date',
        'expire_date',
        'commission_per',
        'post_paid_amount',
        'package_top_up_id',
        'package_top_up_name',
        'package_top_up_amount',
    ];
}
