<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyLostFound extends Model
{
    use HasFactory;

    protected $table = "lost_founds";

    public function bookingDetails(){
        return $this->hasOne(CompanyBooking::class, "id", "booking_id");
    }
}
