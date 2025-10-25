<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyVehicleType extends Model
{
    use HasFactory;
    
    protected $table = "vehicle_types";
    
    protected $casts = [
        "attributes" => "array",
    ];

    public function getFromArrayAttribute($value)
    {
        return explode(",",$value);
    }

    public function getToArrayAttribute($value)
    {
        return explode(",",$value);
    }
    
    public function getPriceArrayAttribute($value)
    {
        return explode(",",$value);
    }

    public function getBackupBidVehicleTypeAttribute($value)
    {
        return explode(",",$value);
    }
}
