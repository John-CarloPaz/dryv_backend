<?php

namespace App\Jobs;

use App\Models\Boundary;
use App\Models\Flooded;
use App\Models\Noah;
use App\Models\Weather;
use App\Jobs\ComputeFloodedPolygonJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ComputeFloodRiskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $weather;

    public function __construct(Weather $weather)
    {
        $this->weather = $weather;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //Search Barangay GID from Boundaries
        $rainfall = $this->weather->runoff;
        $barangay = $this->weather->barangay;
        $barangayName = $barangay->name;
        $barangayCity = $barangay->city;
        $barangayProvince = $barangay->province;
        $gid = null;

        if($barangay){
            $match = Boundary::on('gis_data')
                ->whereRaw('LOWER(adm2_en) ILIKE ?', ['%' . strtolower($barangayProvince) . '%'])
                ->whereRaw('LOWER(adm4_en) ILIKE ?', ['%' . strtolower($barangayName) . '%'])
                ->whereRaw('LOWER(adm3_en) ILIKE ?', ['%' . strtolower($barangayCity) . '%'])
                ->get();
            if ($match->isNotEmpty()) {
                $gid = $match->first()->gid;
            }
        }

        if ($gid === null) {
            Log::warning('No boundary gid match for barangay; skipping flood polygon compute.', [
                'barangay_id' => $this->weather->barangay_id,
                'barangay' => $barangay ? [
                    'name' => $barangayName,
                    'city' => $barangayCity,
                    'province' => $barangayProvince,
                ] : null,
            ]);

            Flooded::updateOrCreate(
                ['barangay_id' => $this->weather->barangay_id],
                [
                    'reported_at' => now(),
                    'accumulated_rainfall' => $rainfall,
                    'risk_level' => 0,
                    'rwr_score' => 0,
                    'flooded_polygon' => null,
                ]
            );

            return;
        }

        $barangayWKT = DB::connection('gis_data')->table('pampanga_boundary')
            ->where('gid', $gid)
            ->selectRaw('ST_AsText(geom) as geom_wkt')
            ->value('geom_wkt');

        if (empty($barangayWKT)) {
            Log::warning('Boundary WKT not found; skipping flood polygon compute.', [
                'barangay_id' => $this->weather->barangay_id,
                'gid' => $gid,
            ]);

            Flooded::updateOrCreate(
                ['barangay_id' => $this->weather->barangay_id],
                [
                    'reported_at' => now(),
                    'accumulated_rainfall' => $rainfall,
                    'risk_level' => 0,
                    'rwr_score' => 0,
                    'flooded_polygon' => null,
                ]
            );

            return;
        }

        $floods = Noah::on('gis_data')
            ->whereRaw(
                'ST_Within(ST_SetSRID(geom, 4326), ST_GeomFromText(?, 4326))',
                [$barangayWKT]
            )
            ->selectRaw('gid, var, ST_AsGeoJSON(geom) as geom')
            ->get();

        $lowThreshold = 49.99;
        $mediumThreshold = 599.99;
        $highThreshold = 600.00;
        $highestRisk = 0;
        $floodData = [];

        foreach ($floods as $flood) {
            $rwr = $flood->var * $rainfall;
            $riskLevel = null;

            if ($rwr >= $highThreshold) {
                $riskLevel = 3;
                $highestRisk = 3;
            } elseif ($rwr >= $mediumThreshold) {
                $riskLevel = max($riskLevel ?? 0, 2);
                if ($highestRisk < 2) $highestRisk = 2;
            } elseif ($rwr >= $lowThreshold) {
                $riskLevel = max($riskLevel ?? 0, 1);
                if ($highestRisk < 1) $highestRisk = 1;
            }

            if ($riskLevel) {
                $floodData[] = [
                    'gid' => $flood->gid,
                    'rwr_score' => $rwr,
                    'risk_level' => $riskLevel,
                ];
            }
        }

        if (!empty($floodData)) {
            Flooded::updateOrCreate(
                ['barangay_id' => $this->weather['barangay_id']],
                [
                    'reported_at' => now(),
                    'accumulated_rainfall' => $rainfall,
                    'risk_level' => $highestRisk,
                    'rwr_score' => (function($data) {
                        $vals = array_column($data, 'rwr_score');
                        if (empty($vals)) return 0;
                        $max = max($vals);
                        return is_numeric($max) ? $max : 0;
                    })($floodData),
                    'flooded_polygon' => json_encode($floodData),
                ]
            );

            try {
                $newExpected = Cache::increment('compute_flooded_expected');
                Log::info('Incremented compute_flooded_expected', [
                    'expected' => $newExpected,
                    'barangay_id' => $this->weather['barangay_id'],
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to increment compute_flooded_expected', ['error' => $e->getMessage(), 'barangay_id' => $this->weather['barangay_id']]);
            }

            Log::info('Dispatching ComputeFloodedPolygonJob', ['barangay_id' => $this->weather['barangay_id'], 'flood_count' => count($floodData)]);
            ComputeFloodedPolygonJob::dispatch($this->weather->barangay_id, $floodData);
        }
        else {
            Flooded::updateOrCreate(
                ['barangay_id' => $this->weather['barangay_id']],
                [
                    'reported_at' => now(),
                    'accumulated_rainfall' => $rainfall,
                    'risk_level' => 0,
                    'rwr_score' => 0,
                    'flooded_polygon' => null,
                ]
            );
            Log::info('No floods detected; set risk_level=0 and cleared flooded_polygon', ['barangay_id' => $this->weather['barangay_id']]);
        }
    }
}
