<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDocumentType;

class DocumentTypeController extends Controller
{
    public function createDocumentType(Request $request){
        try{
            $request->validate([
                'document_name' => 'required|max:255',
                'front_photo' => 'required',
                'back_photo' => 'required',
                'profile_photo' => 'required',
                'has_issue_date' => 'required',
                'has_expiry_date' => 'required',
                'has_number_field' => 'required',
            ]);

            $document = new CompanyDocumentType;
            $document->document_name = $request->document_name;
            $document->front_photo = $request->front_photo;
            $document->back_photo = $request->back_photo;
            $document->profile_photo = $request->profile_photo;
            $document->has_issue_date = $request->has_issue_date;
            $document->has_expiry_date = $request->has_expiry_date;
            $document->has_number_field = $request->has_number_field;
            $document->save();

            return response()->json([
                'success' => 1,
                'message' => "Document saved succesfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editDocumentType(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'document_name' => 'required|max:255',
                'front_photo' => 'required',
                'back_photo' => 'required',
                'profile_photo' => 'required',
                'has_issue_date' => 'required',
                'has_expiry_date' => 'required',
                'has_number_field' => 'required',
            ]);

            $document = CompanyDocumentType::where("id", $request->id)->first();
            $document->document_name = $request->document_name;
            $document->front_photo = $request->front_photo;
            $document->back_photo = $request->back_photo;
            $document->profile_photo = $request->profile_photo;
            $document->has_issue_date = $request->has_issue_date;
            $document->has_expiry_date = $request->has_expiry_date;
            $document->has_number_field = $request->has_number_field;
            $document->save();

            return response()->json([
                'success' => 1,
                'message' => "Document updated succesfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getEditDocumentType(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);

            $document = CompanyDocumentType::where("id", $request->id)->first();
            return response()->json([
                'success' => 1,
                'document_type' =>$document
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteDocumentType(Request $request){
        try{
            $request->validate([
                'id' => 'required',
            ]);

            $document = CompanyDocumentType::where("id", $request->id)->first();
            if(isset($document) && $document != NULL){
                $document->delete();
            }
            return response()->json([
                'success' => 1,
                'message' => "Document type deleted successfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listDocumentType(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $documentType = CompanyDocumentType::orderBy("id", "DESC")->paginate($perPage);

            return response()->json([
                'success' => 1,
                'list' => $documentType
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
