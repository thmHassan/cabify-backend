<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantProviderCredential extends Model
{
    protected $connection = 'central';
    protected $guarded = [];
    protected $casts = ['credentials' => 'encrypted:array', 'last_verified_at' => 'datetime'];
    protected $hidden = ['credentials'];
}
