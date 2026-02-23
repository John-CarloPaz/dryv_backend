<?php

namespace App\Services;

use App\Jobs\ComputeFloodRiskJob;
use App\Models\Barangay;
use App\Models\Weather;
use Illuminate\Support\Facades\Log;

class WeatherProcessingService
{
    /**
     * Core weather computation used by FetchWeatherJob and SimulationController.
     *
     * @param Barangay $barangay
     * @param array $forecastData OpenWeather hourly forecast-style payload (with list[])
     * @param array|null $satelliteData Satellite radiation payload (hourly.global_tilted_irradiance or global_tilted_irradiance)
     * @return Weather
     */
    public static function process(Barangay $barangay, array $forecastData, ?array $satelliteData = null): Weather
    {
        // --- Previous SI and soil moisture ---
        $previousSi = Weather::where('barangay_id', $barangay->id)->value('si_score') ?? 0.0;
        $fieldCapacity = 45.0; // mm
        $soilAbsorptionRate = 0.15; // fraction of rain absorbed

        // Initialize soil moisture from previous SI
        $soilMoisture = $previousSi * $fieldCapacity;

        // --- 1. Extract temps, rainfall, and POP ---
        $accumRain = 0.0;
        $popSum = 0.0;
        $hoursCount = 0;
        $tempMin = $tempMax = null;

        foreach (($forecastData['list'] ?? []) as $hour) {
            $rainHour = isset($hour['rain']['1h']) ? (float)$hour['rain']['1h'] : 0.0;
            $popHour = isset($hour['pop']) ? (float)$hour['pop'] : 0.0;

            $accumRain += $rainHour;
            $popSum += $popHour;
            $hoursCount++;

            if (isset($hour['main']['temp_min'])) {
                $val = (float)$hour['main']['temp_min'];
                $tempMin = $tempMin === null ? $val : min($tempMin, $val);
            }
            if (isset($hour['main']['temp_max'])) {
                $val = (float)$hour['main']['temp_max'];
                $tempMax = $tempMax === null ? $val : max($tempMax, $val);
            }
        }

        $avePop = $hoursCount > 0 ? ($popSum / $hoursCount) * 100.0 : 0.0;
        $tempAve = (is_numeric($tempMin) && is_numeric($tempMax)) ? ($tempMin + $tempMax) / 2.0 : null;

        // --- 2. Extract satellite GHI (MJ/m²/day) ---
        $gtirr_mj_day = 0.0;
        if ($satelliteData) {
            $hours = $satelliteData['hourly']['global_tilted_irradiance'] ?? $satelliteData['global_tilted_irradiance'] ?? [];
            if (!empty($hours)) {
                $gtirr_mj_day = array_sum($hours) * 0.0036; // W/m² hr -> MJ/m² day
            }
        }

        // --- 3. Compute Hargreaves ET ---
        $et_hourly = 0.0;
        $et_daily = 0.0;
        if (is_numeric($tempMin) && is_numeric($tempMax) && $gtirr_mj_day > 0 && $tempAve !== null) {
            $R_a_hourly = $gtirr_mj_day / 24.0; // MJ/m²/hr
            $T_mean = ($tempMin + $tempMax) / 2.0;
            $T_range = max($tempMax - $tempMin, 0.1);
            $et_hourly = 0.0023 * ($T_mean + 17.8) * sqrt($T_range) * $R_a_hourly;
            $et_daily = $et_hourly * 24.0;
        }

        // --- 4. Update soil moisture & SI iteration-safe ---
        foreach (($forecastData['list'] ?? []) as $hour) {
            $rainHour = isset($hour['rain']['1h']) ? (float)$hour['rain']['1h'] : 0.0;

            // Rain infiltrates depending on current soil saturation
            $R_absorbed = $rainHour * $soilAbsorptionRate * (1 - ($soilMoisture / $fieldCapacity));
            $R_absorbed = max(0.0, $R_absorbed);

            // ET reduces soil moisture
            $soilMoisture += $R_absorbed - $et_hourly;
            $soilMoisture = max(0.0, min($fieldCapacity, $soilMoisture));
        }

        $prevRunoff = Weather::where('barangay_id', $barangay->id)->value('runoff') ?? 0.0;

        $currentSi = $soilMoisture / $fieldCapacity;

        // --- 5. Compute runoff using updated soil moisture ---
        // Total infiltrated rain
        $totalInfiltrated = $accumRain * $soilAbsorptionRate * (1 - $previousSi);

        $availableAbsorption = (1 - $currentSi) * $fieldCapacity; // mm the soil can still take
        $infiltratedFromRunoff = min($prevRunoff, $soilAbsorptionRate * $availableAbsorption);
        $adjustedPreviousRunoff = max(0.0, $prevRunoff - $infiltratedFromRunoff);

        $newRunoff = max(0.0, $accumRain - $totalInfiltrated);
        $runoff = $adjustedPreviousRunoff + $newRunoff;
        // --- 6. Persist Weather ---
        $weather = Weather::updateOrCreate(
            ['barangay_id' => $barangay->id],
            [
                'data' => $forecastData,
                'si_score' => $currentSi,
                'fetched_at' => now(),
                'accumulated_rainfall' => $accumRain,
                'ave_pop_percentage' => $avePop,
                'temp_min' => $tempMin,
                'temp_max' => $tempMax,
                'temp_avg' => $tempAve,
                'solar_irradiance' => $gtirr_mj_day,
                'hargreaves_index' => $et_daily,
                'hargreaves_hourly' => $et_hourly,
                'soil_moisture' => $soilMoisture,
                'runoff' => $runoff,
            ]
        );

        Log::info("Processed weather for {$barangay->name}: SI={$currentSi}, Rainfall={$accumRain}, POP={$avePop}, Runoff={$runoff}, TempAve={$tempAve}, GHI={$gtirr_mj_day}, ET={$et_hourly}");

        ComputeFloodRiskJob::dispatch($weather);

        return $weather;
    }
}
