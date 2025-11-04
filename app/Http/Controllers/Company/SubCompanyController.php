<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SubCompany;

class SubCompanyController extends Controller
{
    public function createSubCompany(Request $request){
        try{
            $request->validate([
                'name' => 'required',
                'email' => 'required'
            ]);

            $subCompany = new SubCompany;
            $subCompany->name = $request->name; 
            $subCompany->email = $request->email; 
            $subCompany->save();

            return response()->json([
                'success' => 1,
                'message' => 'Sub company saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editSubCompany(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'name' => 'required',
                'email' => 'required'
            ]);

            $subCompany = SubCompany::where("id", $request->id)->first();
            $subCompany->name = $request->name; 
            $subCompany->email = $request->email; 
            $subCompany->save();

            return response()->json([
                'success' => 1,
                'message' => 'Sub company updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getEditSubCompany(Request $request){
        try{
            $request->validate([
                'id' => 'required'
            ]);

            $data = SubCompany::where("id", $request->id)->first();

            return response()->json([
                'success' => 1,
                'data' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listSubCompany(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $subCompanies = SubCompany::orderBy("id", "DESC")->paginate($perPage);

            return response()->json([
                'success' => 1,
                'list' => $subCompanies
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteSubCompany(Request $request){
        try{
            $request->validate([
                'id' => 'required'
            ]);

            $data = SubCompany::where("id", $request->id)->first();

            if(isset($data) && $data != NULL){
                $data->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Sub company deleted successfully'
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
