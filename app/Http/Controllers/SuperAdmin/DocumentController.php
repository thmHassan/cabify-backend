<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DocumentType;

class DocumentController extends Controller
{
    public function createDocument(Request $request){
        try{
            $request->validate([
                'document_name' => 'required',
                'front_photo' => 'required',
                'back_photo' => 'required',
                'profile_photo' => 'required',
                'has_issue_date' => 'required',
                'has_expiry_date' => 'required'
            ]);

            $document = new DocumentType;
            $document->document_name = $request->document_name;
            $document->front_photo = $request->front_photo;
            $document->back_photo = $request->back_photo;
            $document->profile_photo = $request->profile_photo;
            $document->has_issue_date = $request->has_issue_date;
            $document->has_expiry_date = $request->has_expiry_date;
            $document->save();

            return response()->json([
                'success' => 1,
                'message' => 'Document type saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function editDocument(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'document_name' => 'required',
                'front_photo' => 'required',
                'back_photo' => 'required',
                'profile_photo' => 'required',
                'has_issue_date' => 'required',
                'has_expiry_date' => 'required'
            ]);

            $document = DocumentType::where("id", $request->id)->first();
            $document->document_name = $request->document_name;
            $document->front_photo = $request->front_photo;
            $document->back_photo = $request->back_photo;
            $document->profile_photo = $request->profile_photo;
            $document->has_issue_date = $request->has_issue_date;
            $document->has_expiry_date = $request->has_expiry_date;
            $document->save();

            return response()->json([
                'success' => 1,
                'message' => 'Document type updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function documentList(Request $request){
        try{
            $list = DocumentType::orderBy('id','DESC')->paginate(10);
            return response()->json([
                'success' => 1,
                'message' => 'List fetched successfully',
                'list' => $list
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteDocument(Request $request){
        try{
            $document = DocumentType::where("id", $request->id)->first();
            if(isset($document) && $document != NULL){
                $document->delete();
            }
            return response()->json([
                'success' => 1,
                'message' => 'Document type deleted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getEditDocument(Request $request){
        try{
            $document =DocumentType::where("id", $request->id)->first();
            return response()->json([
                'success' => 1,
                'document' => $document
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
