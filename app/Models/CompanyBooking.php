<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyBooking extends Model
{
    use HasFactory;

    protected $table = "bookings";

    public function userDetail(){
        return $this->hasOne(CompanyUser::class, "id", "user_id");
    }

    public function driverDetail(){
        return $this->hasOne(CompanyDriver::class, "id", "driver");
    }

    public function vehicleDetail(){
        return $this->hasOne(CompanyVehicleType::class, "id", "vehicle");
    }

    public function subCompanyDetail(){
        return $this->hasOne(SubCompany::class, "id", "sub_company");
    }

    public function accountDetail(){
        return $this->hasOne(CompanyAccount::class, "id", "account");
    }
}
