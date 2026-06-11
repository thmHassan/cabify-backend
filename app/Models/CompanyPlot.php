<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyPlot extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = "plots";

    protected $casts = [
        'features' => 'array',
        'backup_plots' => 'array'
    ];
}
