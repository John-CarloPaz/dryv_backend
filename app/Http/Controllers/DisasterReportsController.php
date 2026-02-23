<?php

namespace App\Http\Controllers;

use App\Models\FloodedGeometry;
use App\Http\Resources\BarangayResource;
use Illuminate\Http\JsonResponse;

class DisasterReportsController extends Controller
{
    public function getAllFloodedBarangays(): JsonResponse
    {
        $floodedBarangays = FloodedGeometry::with(['barangay' => function($query) {
            $query->select('id', 'name', 'city', 'province', 'latitude', 'longitude');
        }])
            ->select('barangay_id', 'flooded_polygons')
            ->get()
            ->map(function ($item) {
                return [
                    'barangay' => [
                        'id' => $item->barangay->id,
                        'name' => $item->barangay->name,
                        'city' => $item->barangay->city,
                        'province' => $item->barangay->province,
                        'coordinates' => [
                            'lat' => $item->barangay->latitude,
                            'lng' => $item->barangay->longitude
                        ]
                    ],
                ];
            });

        return response()->json([
            'data' => $floodedBarangays,
            'count' => $floodedBarangays->count()
        ]);
    }
}
