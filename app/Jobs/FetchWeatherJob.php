<?php

namespace App\Jobs;

use App\Models\Barangay;
use App\Models\Weather;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchWeatherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $barangay;
    /**
     * Create a new job instance.
     */
    public function __construct(Barangay $barangay)
    {
        $this->barangay = $barangay;

    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $response = Http::withoutVerifying()->get('https://api.openweathermap.org/data/3.0/onecall', [
            'lat' => $this->barangay->latitude,
            'lon' => $this->barangay->longitude,
            'appid' => config('services.openweather.key'),
            'units' => 'metric',
        ]);

        if ($response->successful()) {

            $weather = Weather::updateOrCreate(
                ['barangay_id' => $this->barangay->id],
                ['data' => $response->json(),
                    'fetched_at' => now()
                ]

            );

            // Trigger computation
            ComputeTotalRainfallJob::dispatch($weather);
        } else {
            Log::error("Failed fetching weather for {$this->barangay->name}");
            Log::info('Weather job data', [
                'barangay_id' => $this->barangay->id,
                'fetched_at' => now(),
                'data' => $response->json(),
            ]);
        }
    }
}
