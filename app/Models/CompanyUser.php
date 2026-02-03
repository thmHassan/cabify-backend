<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\CompanyRating;

class CompanyUser extends Model
{
    use HasFactory;

    protected $table = "users";
    protected $appends = ['rating'];

    public function getRatingAttribute(){
        $rating = CompanyRating::where("user_type", "user")->where("user_id", $this->id)->avg("rating");

        return $rating;
    }
}
