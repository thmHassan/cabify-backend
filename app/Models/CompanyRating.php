<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyRating extends Model
{
    use HasFactory;

    protected $table = "ratings";

    public function bookingDetail(){
        return $this->hasOne(CompanyBooking::class, "id", "booking_id");
    }
}
