<?php

namespace App\Console\Commands;

use App\Jobs\FetchWeatherJob;
use App\Models\Barangay;
use Illuminate\Console\Command;

class FetchAllBarangayWeather extends Command
{
    protected $signature = 'weather:fetch';
    protected $description = 'Fetch OpenWeather data for all barangays';

    public function handle()
    {
        $barangays = Barangay::take(5)->get();
        foreach ($barangays as $b) {
            FetchWeatherJob::dispatch($b);
        }

        $this->info('All jobs dispatched!');
    }
}
