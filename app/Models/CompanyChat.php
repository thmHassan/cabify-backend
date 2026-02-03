<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyChat extends Model
{
    use HasFactory;

    protected $table = "chats";

    public function rideDetail(){
        return $this->hasOne(CompanyBooking::class, 'id', 'ride_id');
    }

    public function userDetail(){
        return $this->hasOne(CompanyUser::class, 'id', 'user_id');
    }

    public function driverDetail(){
        return $this->hasOne(CompanyDriver::class, 'id', 'driver_id');
    }
}
