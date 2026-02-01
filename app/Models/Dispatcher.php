<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use App\Models\CompanyBooking;

class Dispatcher extends Authenticatable implements JWTSubject
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = "dispatcher";
    protected $appends = ["active_rides", 'completed_rides'];
    
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function getActiveRidesAttribute(){
        $count = CompanyBooking::where("dispatcher_id", $this->id)
                ->where(function($q){
                    $q->where("booking_status", 'arrived')
                        ->orWhere("booking_status", 'started')
                        ->orWhere("booking_status", 'ongoing');
                })
                ->whereDate("booking_date", date("Y-m-d"))->count();
        return $count;
    }

    public function getCompletedRidesAttribute(){
        $count = CompanyBooking::where("dispatcher_id", $this->id)
                ->where("booking_status", "completed")
                ->whereDate("booking_date", date("Y-m-d"))->count();
        return $count;
    }
}
