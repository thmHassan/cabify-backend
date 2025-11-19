<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\DriverDocument;

class CompanyDocumentType extends Model
{
    use HasFactory;

    protected $table = "documents";

    protected $appends = ['uploaded_document'];

    public function getUploadedDocumentAttribute(){
        return DriverDocument::where("driver_id", auth('driver')->user()->id)->where("document_id", $this->id)->first();
    }
}
