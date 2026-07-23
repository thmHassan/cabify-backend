<?php

namespace App\Models;

use App\Services\PreBookingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyBooking extends Model
{
    use HasFactory;

    protected $table = "bookings";

    protected $appends = ['pre_booking', 'pickup_at_local'];

    protected $casts = [
        'is_scheduled' => 'boolean',
        'pickup_at' => 'datetime',
        'dispatch_released' => 'boolean',
        'dispatch_release_at' => 'datetime',
        'dispatch_release_override' => 'boolean',
        'reminder_minutes' => 'integer',
        'reminder_sent_at' => 'datetime',
        'bidding_fallback' => 'boolean',
    ];

    public function getPreBookingAttribute(): bool
    {
        return app(PreBookingService::class)->bookingQualifiesAsPreBooking($this);
    }

    public function getPickupAtAttribute($value): ?\Carbon\Carbon
    {
        if ($value) {
            return \Carbon\Carbon::parse($value, 'UTC')->utc();
        }

        if (!$this->booking_date || !$this->pickup_time || strtolower((string) $this->pickup_time) === 'asap') {
            return null;
        }

        try {
            $timezone = $this->pickup_timezone ?: app(PreBookingService::class)->companyTimezone();

            return \Carbon\Carbon::parse(
                $this->booking_date . ' ' . $this->pickup_time,
                $timezone
            )->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getPickupAtLocalAttribute(): ?string
    {
        $pickupAt = $this->pickup_at;

        if (!$pickupAt) {
            return null;
        }

        try {
            $timezone = $this->pickup_timezone ?: app(PreBookingService::class)->companyTimezone();

            return $pickupAt->copy()->setTimezone($timezone)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

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
    
    public function ratingDetail(){
        return $this->hasMany(CompanyRating::class, "booking_id", "id");
    }

    public function waitingDetail(){
        return $this->hasMany(CompanyWaitingTimeLog::class, "booking_id", "id");
    }
}
