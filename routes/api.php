<?php

use App\Models\Barangay;
use App\Models\Boundary;
use App\Models\Noah;
use Clickbar\Magellan\Data\Geometries\Geometry;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Database\PostgisFunctions\ST;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

//Barangay Routes
Route::get('/barangay', [\App\Http\Controllers\BarangayController::class, 'getAllBarangay']);
Route::get('/barangay/city/{city}', [\App\Http\Controllers\BarangayController::class, 'getBarangayByCity']);
Route::get('/barangay/province/{province}', [\App\Http\Controllers\BarangayController::class, 'getBarangayByProvince']);
Route::get('/barangay/id/{id}', [\App\Http\Controllers\BarangayController::class, 'getBarangayById']);
Route::get('/barangay/search/{name}', [\App\Http\Controllers\BarangayController::class, 'searchBarangayByName']);
Route::post('/barangay', [\App\Http\Controllers\BarangayController::class, 'createBarangay']);
Route::put('/barangay/{id}', [\App\Http\Controllers\BarangayController::class, 'updateBarangay']);
Route::delete('/barangay/{id}', [\App\Http\Controllers\BarangayController::class, 'deleteBarangay']);
//End of Barangay Routes
Route::get('/find-polygon', function () {
    $lat = 15.065303;
    $lon = 120.720766;



    $point = Point::makeGeodetic($lat, $lon);

    $polygon = Noah::query()
        ->whereRaw(ST::contains('geom', $point))
        ->first(); // Use get() if expecting multiple polygons

    if ($polygon) {
        return response()->json($polygon);
    }

    return response()->json(['message' => 'No polygon contains this point']);
});

Route::get('/floods-in-barangay/{gid}', function ($gid) {
    // 1️ Fetch barangay as EWKB
    $barangayEWKB = DB::table('pampanga_boundary')
        ->where('gid', $gid)
        ->selectRaw('ST_AsEWKB(geom) as geom')
        ->value('geom');

    if (!$barangayEWKB) {
        return response()->json(['message' => 'Barangay not found']);
    }

    // 2️ Get intersecting floods
    $floods = Noah::query()
        ->whereRaw('ST_Intersects(geom, ?)', [$barangayEWKB])
        ->selectRaw('gid, var, ST_AsGeoJSON(geom) as geom')
        ->get();

    // 3️ Convert to GeoJSON FeatureCollection
    $features = $floods->map(function ($flood) {
        return [
            'type' => 'Feature',
            'geometry' => json_decode($flood->geom),
            'properties' => [
                'gid' => $flood->gid,
                'var' => $flood->var,
            ],
        ];
    });

    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
    ];
    return $floods;
});

Route::get('/boundaries', function () {
    $searchBarangay = 'San Jose';
    $searchCity = 'San Fernando';

    $matches = Boundary::query()
        ->where('adm2_en', 'Pampanga')
        ->whereRaw('LOWER(adm4_en) ILIKE ?', ['%' . strtolower($searchBarangay) . '%'])
        ->whereRaw('LOWER(adm3_en) ILIKE ?', ['%' . strtolower($searchCity) . '%'])
        ->get();

    if ($matches->isEmpty()) {
        return response()->json(['message' => 'No matching boundaries found']);
    }
    return $matches->first()->gid;
});

//Risk Areas
//Disaster Reports
//Evacuation Centers
//User Management
//Notifications
//Analytics
//Settings
//Support and Feedback
