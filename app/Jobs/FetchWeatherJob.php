<?php

namespace App\Jobs;

use App\Models\Barangay;
use App\Services\WeatherProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchWeatherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Barangay $barangay;

    public function __construct(Barangay $barangay)
    {
        $this->barangay = $barangay;
    }

    public function handle(): void
    {
        $date = now()->toDateString();

        try {
            // --- 1. Fetch forecast, current, and satellite data concurrently ---
            [$forecastResp, $currentResp, $satResp] = Http::withoutVerifying()->pool(function ($pool) use ($date) {
                return [
                    $pool->get('https://pro.openweathermap.org/data/2.5/forecast/hourly', [
                        'lat' => $this->barangay->latitude,
                        'lon' => $this->barangay->longitude,
                        'appid' => config('services.openweather.key'),
                        'units' => 'metric',
                        'cnt' => 6, // multi-hour simulation
                    ]),
                    $pool->get('https://api.openweathermap.org/data/2.5/weather', [
                        'lat' => $this->barangay->latitude,
                        'lon' => $this->barangay->longitude,
                        'appid' => config('services.openweather.key'),
                        'units' => 'metric',
                    ]),
                    $pool->get('https://satellite-api.open-meteo.com/v1/archive', [
                        'latitude' => $this->barangay->latitude,
                        'longitude' => $this->barangay->longitude,
                        'hourly' => 'global_tilted_irradiance',
                        'models' => 'satellite_radiation_seamless',
                        'start_date' => $date,
                        'end_date' => $date,
                    ]),
                ];
            });

            if (!$forecastResp || !$forecastResp->successful()) {
                throw new \Exception("Forecast fetch failed for {$this->barangay->name}");
            }

            $forecastData = $forecastResp->json();
            $satelliteData = ($satResp && $satResp->successful()) ? $satResp->json() : null;

            // --- 2. Delegate processing (SSI, Hargreaves, runoff, soil moisture) ---
            WeatherProcessingService::process($this->barangay, $forecastData, $satelliteData);

        } catch (\Throwable $e) {
            Log::error("Failed FetchWeatherJob for {$this->barangay->name}: {$e->getMessage()}");

            // fallback: clear weather record
            \App\Models\Weather::updateOrCreate(
                ['barangay_id' => $this->barangay->id],
                [
                    'data' => null,
                    'si_score' => 0.0,
                    'soil_moisture' => 0.0, // reset
                    'fetched_at' => now(),
                ]
            );
        }
    }
}
