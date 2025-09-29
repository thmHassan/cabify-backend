<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnboardingRequest extends Model
{
    use HasFactory;

    protected $table = "onboarding_requests";

    protected $guarded = [];
}
