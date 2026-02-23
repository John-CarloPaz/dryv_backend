<?php

namespace App\Jobs;

use App\Models\FloodedGeometry;
use App\Models\Noah;
use Illuminate\Support\Facades\Cache;
use App\Jobs\UploadGeoJsonToMapbox;
use App\Jobs\SyncCurrentFloodPolygonsJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ComputeFloodedPolygonJob implements ShouldQueue
{
    use Queueable;

    private ?int $barangayId = null;
    private array $floodData = [];

    public function __construct(?int $barangayId, array $floodData)
    {
        $this->barangayId = $barangayId;
        $this->floodData = $floodData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (empty($this->barangayId)) {
            Log::error('ComputeFloodedPolygonJob called with null/empty barangay_id; skipping write.', [
                'barangay_id' => $this->barangayId,
                'flood_count' => is_array($this->floodData) ? count($this->floodData) : null,
            ]);
            return;
        }

        $features = [];

        foreach ($this->floodData as $flood) {
            $geometry = Noah::on('gis_data')
                ->where('gid', $flood['gid'])
                ->selectRaw('ST_AsGeoJSON(geom) as geom')
                ->value('geom');

            if ($geometry) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => json_decode($geometry),
                    'properties' => [
                        'gid' => $flood['gid'],
                        'rwr' => $flood['rwr_score'],
                        'risk_level' => $flood['risk_level']
                    ]
                ];
            }
        }

        $geoJson = [
            'type' => 'FeatureCollection',
            'features' => $features
        ];

        FloodedGeometry::updateOrCreate(
            ['barangay_id' => $this->barangayId],
            ['flooded_geojson' => json_encode($geoJson)]
        );

        // Increment the completed counter. When completed == expected,
        // dispatch a single UploadGeoJsonToMapbox job and reset counters.
        try {
            $completed = Cache::increment('compute_flooded_completed');
            $expected = Cache::get('compute_flooded_expected', null);

            Log::info('Polygon job completed counter incremented', [
                'barangay_id' => $this->barangayId,
                'completed' => $completed,
                'expected' => $expected,
            ]);

            if ($expected !== null && $completed >= $expected) {
                // Reset counters to avoid duplicate dispatches
                Cache::forget('compute_flooded_expected');
                Cache::forget('compute_flooded_completed');

                Log::info('All polygon jobs complete — dispatching flood sync and Mapbox upload', [
                    'completed' => $completed,
                    'expected'  => $expected,
                ]);

                // First, rebuild current_flood_polygons in the gis_data database
                SyncCurrentFloodPolygonsJob::dispatch();

                // Then, update the Mapbox tileset source
                UploadGeoJsonToMapbox::dispatch();
            }
        } catch (\Exception $e) {
            Log::error('Error updating polygon completion counters or dispatching upload', ['error' => $e->getMessage(), 'barangay_id' => $this->barangayId]);
        }
        
    }
}
