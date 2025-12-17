<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverDocument extends Model
{
    use HasFactory;

    protected $table = "drivers_documents";

    public function documentDetail(){
        return $this->hasOne(CompanyDocumentType::class, 'id', 'document_id');
    }
}
