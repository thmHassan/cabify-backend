<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Plot;

class PlotController extends Controller
{
    private function normalizePlotFeatures($features)
    {
        $featurePayload = is_string($features) ? json_decode($features, true) : $features;
        if (!is_array($featurePayload)) {
            throw new \Exception("Invalid plot features payload");
        }

        $feature = $featurePayload;
        if (isset($feature['features'][0]['geometry'])) {
            $feature = $feature['features'][0];
        }

        $geometry = $feature['geometry'] ?? null;
        if (!is_array($geometry) || !isset($geometry['coordinates'])) {
            throw new \Exception("Invalid plot geometry payload");
        }

        $coordinates = $geometry['coordinates'];
        if (is_string($coordinates)) {
            $coordinates = json_decode($coordinates, true);
        }
        if (!is_array($coordinates)) {
            throw new \Exception("Invalid geometry coordinates");
        }

        $normalizedCoordinates = $this->normalizeCoordinates($coordinates);
        if (!$normalizedCoordinates) {
            throw new \Exception(
                "Invalid polygon coordinates. Please send valid GeoJSON coordinates in [longitude, latitude] format."
            );
        }

        $geometry['coordinates'] = $normalizedCoordinates;
        $feature['geometry'] = $geometry;

        if (isset($featurePayload['features']) && is_array($featurePayload['features'])) {
            $featurePayload['features'][0] = $feature;
        } else {
            $featurePayload = $feature;
        }

        return $featurePayload;
    }

    private function normalizeCoordinates($coordinates, $allowNested = true)
    {
        if (!is_array($coordinates)) {
            return null;
        }

        if (count($coordinates) >= 2
            && !is_array($coordinates[0])
            && !is_array($coordinates[1])
            && is_numeric($coordinates[0])
            && is_numeric($coordinates[1])) {
            return $this->normalizePointPair([$coordinates[0], $coordinates[1]]);
        }

        $normalized = [];
        foreach ($coordinates as $pointOrRing) {
            if (!is_array($pointOrRing)) {
                return null;
            }

            $result = $this->normalizeCoordinates($pointOrRing, $allowNested);
            if ($result === null) {
                return null;
            }
            $normalized[] = $result;
        }

        return $normalized;
    }

    private function normalizePointPair(array $point)
    {
        $lng = is_numeric($point[0] ?? null) ? (float) $point[0] : null;
        $lat = is_numeric($point[1] ?? null) ? (float) $point[1] : null;

        if (!is_finite($lng) || !is_finite($lat)) {
            return null;
        }

        if (abs($lng) <= 180 && abs($lat) <= 90) {
            return [$lng, $lat];
        }

        if (abs($lng) <= 90 && abs($lat) <= 180) {
            return [$lat, $lng];
        }

        return null;
    }

    public function createPlot(Request $request){
        try{
            $request->validate([
                'name' => 'required|max:255',
                'features' => 'required',
            ]);

            $plot = new Plot;
            $plot->name = $request->name;
            $plot->features = json_encode($this->normalizePlotFeatures($request->features));
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
            if (!$plot) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Plot not found',
                ], 404);
            }
            $plot->name = $request->name;
            $plot->features = json_encode($this->normalizePlotFeatures($request->features));
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
