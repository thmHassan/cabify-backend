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

            $documentType = CompanyDocumentType::where('id', $request->document_id)->first();
            if (!$documentType) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Document type not found'
                ], 404);
            }

            if ($documentType->front_photo === 'yes' && !$request->hasFile('front_photo')) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Front photo is required'
                ], 422);
            }

            if ($documentType->back_photo === 'yes' && !$request->hasFile('back_photo')) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Back photo is required'
                ], 422);
            }

            if ($documentType->profile_photo === 'yes' && !$request->hasFile('profile_photo')) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Profile photo is required'
                ], 422);
            }

            if ($documentType->has_issue_date === 'yes' && !$request->filled('has_issue_date')) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Issue date is required'
                ], 422);
            }

            if ($documentType->has_expiry_date === 'yes' && !$request->filled('has_expiry_date')) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Expiry date is required'
                ], 422);
            }

            if ($documentType->has_number_field === 'yes' && !$request->filled('has_number_field')) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Document number is required'
                ], 422);
            }

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
                $issueDate = strtotime((string) $request->has_issue_date);
                if (!$issueDate) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Issue date must be a valid date'
                    ], 422);
                }
                $newDocument->has_issue_date = date('Y-m-d', $issueDate);
            }

            if(isset($request->has_expiry_date) && $request->has_expiry_date != NULL){
                $expiryDate = strtotime((string) $request->has_expiry_date);
                if (!$expiryDate) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Expiry date must be a valid date'
                    ], 422);
                }
                $newDocument->has_expiry_date = date('Y-m-d', $expiryDate);
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
            ], 400);
        }
    }
}
