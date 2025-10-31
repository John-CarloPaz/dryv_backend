<?php

namespace App\Jobs;

use App\Models\Boundary;
use App\Models\Noah;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComputeTotalRainfallJob implements ShouldQueue
{
    use Queueable;
    private $weather;
    public function __construct($weather)
    {
        $this->weather = $weather;
    }

    public function handle(): void
    {
        $data = $this->weather->data;
        $totalRainfall = 0.0;
        $twelvehours = array_slice($data['hourly'], 0, 12);

        if (isset($data['hourly']) && is_array($data['hourly'])) {
            foreach ($twelvehours as $hour) {
                if (isset($hour['rain']['1h']) && $hour['pop'] >= 0.6) {
                    $totalRainfall += $hour['rain']['1h'];
                }
            }
        }
        $previousAccumulated = $this->weather->accumulated_rainfall ?? 0.0;
        $newAccumulated = $previousAccumulated + $totalRainfall;

        $this->weather->accumulated_rainfall = $newAccumulated;
        $this->weather->save();
        Log::info($this->weather);
        ComputeFloodRiskJob::dispatch($this->weather);
    }
}
