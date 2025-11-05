<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Plot;

class PlotController extends Controller
{
    public function createPlot(Request $request){
        try{
            $request->validate([
                'name' => 'required|max:255',
                'features' => 'required',
            ]);

            $plot = new Plot;
            $plot->name = $request->name;
            $plot->features = json_encode($request->features);
            $plot->save();

            return response()->json([
                'success' => 1,
                'message' => 'Plot saved successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function editPlot(Request $request){
        try{
            $request->validate([
                'id' => 'required',
                'name' => 'required|max:255',
                'features' => 'required',
            ]);

            $plot = Plot::where("id", $request->id)->first();
            $plot->name = $request->name;
            $plot->features = json_encode($request->features);
            $plot->save();

            return response()->json([
                'success' => 1,
                'message' => 'Plot updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getEditPlot(Request $request){
        try{
            $plot = Plot::where("id", $request->id)->first();
            return response()->json([
                'success' => 1,
                'plot' => $plot
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function plotList(Request $request){
        try{
            $perPage = 10;
            if(isset($request->perPage) && $request->perPage != NULL){
                $perPage = $request->perPage;
            }
            $plots = Plot::orderBy("id", "DESC");
            if(isset($request->search) && $request->search != NULL){
                $plots->where(function($query) use ($request){
                    $query->where("name", "LIKE" ,"%".$request->search."%")
                            ->orWhere("features", "LIKE" ,"%".$request->search."%");
                });
            }
            $data = $plots->paginate($perPage);
            return response()->json([
                'success' => 1,
                'list' => $data
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deletePlot(Request $request){
        try{
            $plot = Plot::where("id", $request->id)->first();

            if(isset($plot) && $plot != NULL){
                $plot->delete();
            }
            return response()->json([
                'success' => 1,
                'message' => 'Plot deleted successfully'
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
