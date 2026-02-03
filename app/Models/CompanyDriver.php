<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\CompanyRating;

class CompanyDriver extends Authenticatable implements JWTSubject
{
    use HasFactory, SoftDeletes;

    protected $table = "drivers";
    protected $appends = ['rating'];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function getRatingAttribute(){
        $rating = CompanyRating::where("user_type", "driver")->where("user_id", $this->id)->avg("rating");

        return $rating;
    }
}
