<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantMapConfiguration extends Model
{
    protected $connection = 'central';
    protected $guarded = [];
    protected $casts = ['allow_platform_fallback' => 'boolean'];
}
