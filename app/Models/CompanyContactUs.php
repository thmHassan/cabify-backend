<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyContactUs extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = "contact_us";

    protected $casts = [
        'responded_at' => 'datetime',
    ];
}
