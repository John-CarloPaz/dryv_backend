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
use App\Http\Controllers\SafeRouteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FloodNearbyController;
use App\Http\Controllers\CommunityFloodReportController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');
    Route::post('/forgot-password-otp', [AuthController::class, 'forgotPasswordOtp'])->middleware('throttle:auth');
    Route::post('/reset-password-otp', [AuthController::class, 'resetPasswordOtp'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::delete('/account', [AuthController::class, 'deleteAccount']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    });
});

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

// Simulation endpoint for posting synthetic weather JSON
Route::post('/simulate-weather', [\App\Http\Controllers\SimulationController::class, 'simulate']);


// Safe routing endpoint (POST) used by Flutter client
Route::post('/route/safe', [SafeRouteController::class, 'route']);

// Flood proximity check (GET) used by mobile client
Route::get('/flood/nearby', [FloodNearbyController::class, 'nearby']);

// Community-based flood reporting (GET nearby segments)
Route::get('/flood/community-report/nearby', [CommunityFloodReportController::class, 'nearby'])
    ->middleware('auth:sanctum');

// Community-based flood reporting (GET all flooded road segments)
Route::get('/flood/community-report/flooded-roads', [CommunityFloodReportController::class, 'index'])
    ->middleware('auth:sanctum');

// Community-based flood reporting (POST) used by mobile client
Route::post('/flood/community-report', [CommunityFloodReportController::class, 'store'])
    ->middleware('auth:sanctum');

Route::get('/report-flooded-barangay', [\App\Http\Controllers\DisasterReportsController::class, 'getAllFloodedBarangays']);
