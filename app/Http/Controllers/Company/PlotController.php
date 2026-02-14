<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyPlot;
use App\Models\CompanySetting;

class PlotController extends Controller
{
    public function createPlot(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|max:255',
                'features' => 'required',
            ]);

            $plot = new CompanyPlot;
            $plot->name = $request->name;
            $plot->features = json_encode($request->features);
            $plot->save();

            $settings = CompanySetting::orderBy("id", "DESC")->first();
            if ($settings) {
                $settings->maps_api_count = ($settings->maps_api_count ?? 0) + 1;
                $settings->last_use_map_api = \Carbon\Carbon::now();
                $settings->save();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Plot saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function editPlot(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'name' => 'required|max:255',
                'features' => 'required',
            ]);

            $plot = CompanyPlot::where("id", $request->id)->first();
            $plot->name = $request->name;
            $plot->features = json_encode($request->features);
            $plot->save();

            return response()->json([
                'success' => 1,
                'message' => 'Plot updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getEditPlot(Request $request)
    {
        try {
            $plot = CompanyPlot::where("id", $request->id)->first();
            return response()->json([
                'success' => 1,
                'plot' => $plot
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function plotList(Request $request)
    {
        try {
            $perPage = 10;
            if (isset($request->perPage) && $request->perPage != NULL) {
                $perPage = $request->perPage;
            }
            $plots = CompanyPlot::orderBy("id", "DESC");
            if (isset($request->search) && $request->search != NULL) {
                $plots->where(function ($query) use ($request) {
                    $query->where("name", "LIKE", "%" . $request->search . "%")
                        ->orWhere("features", "LIKE", "%" . $request->search . "%");
                });
            }
            $data = $plots->paginate($perPage);
            return response()->json([
                'success' => 1,
                'list' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deletePlot(Request $request)
    {
        try {
            $plot = CompanyPlot::where("id", $request->id)->first();

            if (isset($plot) && $plot != NULL) {
                $plot->delete();
            }
            return response()->json([
                'success' => 1,
                'message' => 'Plot deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function allPlot(Request $request)
    {
        try {
            $plots = CompanyPlot::orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'list' => $plots
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function storeBackupPlot(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|max:255',
                'backup_plot_array' => 'required',
            ]);

            $plot = CompanyPlot::where("id", $request->id)->first();
            $plot->backup_plots = $request->backup_plot_array;
            $plot->save();

            return response()->json([
                'success' => 1,
                'message' => "Backup plot added succesfully"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getBackupPlot(Request $request)
    {
        try {
            $plots = CompanyPlot::orderBy("id", "DESC")->get();

            foreach ($plots as $plot) {
                if (is_array($plot->backup_plots) && $plot->backup_plots != NULL) {
                    $backupPlots = CompanyPlot::whereIn("id", $plot->backup_plots)->get();
                    $plot->backup_plots_data = $backupPlots;
                }
            }

            return response()->json([
                'success' => 1,
                'list' => $plots
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
