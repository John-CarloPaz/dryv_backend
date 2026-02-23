<?php

namespace App\Console\Commands;

use App\Jobs\FetchWeatherJob;
use App\Models\Barangay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Console\Command;

class FetchAllBarangayWeather extends Command
{
    protected $signature = 'weather:fetch';
    protected $description = 'Fetch OpenWeather data for all barangays';
    
    public function handle()
    {
        // Only process the first 5 barangays for testing / limited runs
        $barangays = Barangay::take(3)->get();

        $count = $barangays->count();

        // Initialize Redis-backed counters. We'll increment `compute_flooded_expected`
        // from `ComputeFloodRiskJob` only when there is actual flood geometry to
        // compute, and `ComputeFloodedPolygonJob` will increment `compute_flooded_completed`.
        Cache::put('compute_flooded_expected', 0, 3600);
        Cache::put('compute_flooded_completed', 0, 3600);

        foreach ($barangays as $b) {
            FetchWeatherJob::dispatch($b);
        }

        $this->info("Dispatched fetch jobs for {$count} barangays");
    }
}
