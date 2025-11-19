<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDocumentType;
use App\Models\DriverDocument;

class DocumentController extends Controller
{
    public function documentList(){
        try{
            $list = CompanyDocumentType::orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'message' => 'Document list fetched successfully',
                'list' => $list
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function documentUpload(Request $request){
        try{
            $folderName = $request->header('database');

            $request->validate([
                'document_id' => 'required'
            ]);

            $newDocument = new DriverDocument;
            $newDocument->document_id = $request->document_id;
            $newDocument->driver_id = auth('driver')->user()->id;
            $newDocument->document_name = $request->document_name;

            if(isset($request->front_photo) && $request->front_photo != NULL){
                $file = $request->file('front_photo');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path($folderName.'/front_photo'), $filename);
                $newDocument->front_photo = $folderName.'/front_photo/'.$filename;
            }

            if(isset($request->back_photo) && $request->back_photo != NULL){
                $file = $request->file('back_photo');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path($folderName.'/back_photo'), $filename);
                $newDocument->back_photo = $folderName.'/back_photo/'.$filename;
            }

            if(isset($request->profile_photo) && $request->profile_photo != NULL){
                $file = $request->file('profile_photo');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path($folderName.'/profile_photo'), $filename);
                $newDocument->profile_photo = $folderName.'/profile_photo/'.$filename;
            }

            if(isset($request->has_issue_date) && $request->has_issue_date != NULL){
                $newDocument->has_issue_date = $request->has_issue_date;
            }

            if(isset($request->has_expiry_date) && $request->has_expiry_date != NULL){
                $newDocument->has_expiry_date = $request->has_expiry_date;
            }

            if(isset($request->has_number_field) && $request->has_number_field != NULL){
                $newDocument->has_number_field = $request->has_number_field;
            }
            $newDocument->save();

            return response()->json([
                'success' => 1,
                'message' => 'Document uploaded successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }
}
