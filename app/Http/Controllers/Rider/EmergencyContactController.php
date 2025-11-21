<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EmergencyContact;

class EmergencyContactController extends Controller
{
    public function addEmergencyContact(Request $request){
        try{
            $request->validate([
                'name' => 'required',
                'country_code' => 'required',
                'number' => 'required'
            ]);

            $newContact = new EmergencyContact;
            $newContact->user_id = auth('rider')->user()->id;
            $newContact->name = $request->name;
            $newContact->country_code = $request->country_code;
            $newContact->number = $request->number;
            $newContact->save();

            return response()->json([
                'success' => 1,
                'message' => 'Emergency contact created successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editEmergencyContact(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'name' => 'required',
                'country_code' => 'required',
                'number' => 'required'
            ]);

            $newContact = EmergencyContact::where("id", $request->id)->first();
            $newContact->user_id = auth('rider')->user()->id;
            $newContact->name = $request->name;
            $newContact->country_code = $request->country_code;
            $newContact->number = $request->number;
            $newContact->save();

            return response()->json([
                'success' => 1,
                'message' => 'Emergency contact updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteEmergencyContact(Request $request){
        try{
            $request->validate([
                'id' => 'required'
            ]);

            $contact = EmergencyContact::where("id", $request->id)->first();
            if(isset($contact) && $contact != NULL){
                $contact->delete();
            }
            return response()->json([
                'success' => 1,
                'message' => 'Emergency contact deleted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listEmergencyContact(Request $request){
        try{
            $list = EmergencyContact::orderBy("id", "DESC")->paginate(10);

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
            ]);
        }
    }
}
