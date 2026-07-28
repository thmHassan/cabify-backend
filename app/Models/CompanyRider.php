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

class CompanyRider extends Authenticatable implements JWTSubject
{
    use HasFactory, SoftDeletes;

    protected $table = "users";
    protected $appends = ['rating'];

    protected $hidden = [
        'password',
        'remember_token',
        'otp',
        'otp_expires_at',
    ];

    protected $casts = [
        'email_verified' => 'boolean',
        'email_verified_at' => 'datetime',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'auth_version' => (int) ($this->auth_version ?? 0),
        ];
    }

    public function getRatingAttribute(){
        $rating = CompanyRating::where("user_type", "user")->where("user_id", $this->id)->avg("rating");

        return $rating;
    }
}
