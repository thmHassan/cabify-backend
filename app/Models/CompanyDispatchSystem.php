<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyDispatchSystem extends Model
{
    use HasFactory;

    protected $table = "dispatch_system";
    protected $primaryKey = 'id';

}
