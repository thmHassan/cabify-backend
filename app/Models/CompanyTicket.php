<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyTicket extends Model
{
    use HasFactory;

    protected $table = 'tickets';

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        if (!$this->image) {
            return null;
        }

        return rtrim(config('app.url'), '/') . '/' . ltrim($this->image, '/');
    }
}
