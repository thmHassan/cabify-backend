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
}
