<?php

namespace App\Http\Controllers;

use App\Jobs\ComputeFloodRiskJob;
use App\Models\Weather;
use App\Models\Barangay;
use App\Services\WeatherProcessingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SimulationController extends Controller
{
    /**
     * Accept simulated weather JSON and dispatch jobs.
     * POST /api/simulate-weather
     * Body: { "barangay_id": 1, "data": { ... } }
     */
    public function simulate(Request $request)
    {
        $payload = $request->validate([
            'barangay_id' => 'required|integer|exists:barangays,id',
            'data' => 'required|array',
        ]);

        // Reset flood computation counters for simulation
        Cache::put('compute_flooded_expected', 0, 3600);
        Cache::put('compute_flooded_completed', 0, 3600);

        $barangay = Barangay::findOrFail($payload['barangay_id']);
        $simPayload = $payload['data'];

        // --- Extract forecast and satellite data ---
        $forecast = $simPayload['weather_hourly'] ?? [];
        $satellite = $simPayload['solar_irradiance'] ?? null;

        // --- Normalize temps to Celsius if in Kelvin ---
        foreach (($forecast['list'] ?? []) as $idx => $hour) {
            if (isset($hour['main']['temp_min']) && $hour['main']['temp_min'] > 200) {
                $forecast['list'][$idx]['main']['temp_min'] = $hour['main']['temp_min'] - 273.15;
            }
            if (isset($hour['main']['temp_max']) && $hour['main']['temp_max'] > 200) {
                $forecast['list'][$idx]['main']['temp_max'] = $hour['main']['temp_max'] - 273.15;
            }
        }

        // --- Process weather simulation ---
        $weather = WeatherProcessingService::process($barangay, $forecast, $satellite);

        // Optional: Log soil moisture for simulation debugging
        Log::info("Simulated weather for {$barangay->name}: SI={$weather->si_score}, SoilMoisture={$weather->soil_moisture}");

        return response()->json([
            'message' => 'simulation accepted',
            'weather_id' => $weather->id,
            'si_score' => $weather->si_score,
            'soil_moisture' => $weather->soil_moisture ?? null,
            'runoff' => $weather->runoff,
        ], 202);
    }
}
