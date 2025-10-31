<?php

namespace App\Jobs;

use App\Models\Boundary;
use App\Models\Flooded;
use App\Models\Noah;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComputeFloodRiskJob implements ShouldQueue
{
    use Queueable;

    private $weather;
    public function __construct($weather)
    {
        $this->weather = $weather;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //Search Barangay GID from Boundaries
        $rainfall = $this->weather['accumulated_rainfall'];
        $barangay = $this->weather->barangay;
        $barangayName = $barangay->name;
        $barangayCity = $barangay->city;
        $barangayProvince = $barangay->province;
        $gid = null;

        if($barangay){
            $match = Boundary::query()
                ->whereRaw('LOWER(adm2_en) ILIKE ?', ['%' . strtolower($barangayProvince) . '%'])
                ->whereRaw('LOWER(adm4_en) ILIKE ?', ['%' . strtolower($barangayName) . '%'])
                ->whereRaw('LOWER(adm3_en) ILIKE ?', ['%' . strtolower($barangayCity) . '%'])
                ->get();
            if ($match->isNotEmpty()) {
                $gid = $match->first()->gid;
            }
        }

        // Get intersecting floods
        $barangayEWKB = DB::table('pampanga_boundary')
            ->where('gid', $gid)
            ->selectRaw('ST_AsEWKB(geom) as geom')
            ->value('geom');

        $floods = Noah::query()
            ->whereRaw('ST_Intersects(geom, ?)', [$barangayEWKB])
            ->selectRaw('gid, var, ST_AsGeoJSON(geom) as geom')
            ->get();

        // Determine flood risk level based on RWR thresholds
        $lowThreshold = 10;
        $mediumThreshold = 15;
        $highThreshold = 20;
        $highestRisk = 'None';
        $floodData = [];

        foreach ($floods as $flood) {
            $rwr = $flood->var * $rainfall;
            $riskLevel = null;

            if ($rwr >= $highThreshold) {
                $riskLevel = 3;
                $highestRisk = 'High';
            } elseif ($rwr >= $mediumThreshold) {
                $riskLevel = max($riskLevel ?? 0, 2);
                if ($highestRisk !== 'High') $highestRisk = 'Medium';
            } elseif ($rwr >= $lowThreshold) {
                $riskLevel = max($riskLevel ?? 0, 1); // Low
                if (!in_array($highestRisk, ['High', 'Medium'])) $highestRisk = 'Low';
            }

            if ($riskLevel) {
                $floodData[] = [
                    'gid' => $flood->gid,
                    'rwr' => $rwr,
                    'risk_level' => $riskLevel,
                ];
            }
        }

        Log::info('Flood Data:', ['floodData' => $floodData]);

        if (!empty($floodData)) {
            Flooded::updateOrCreate(
                ['barangay_id' => $this->weather['barangay_id']],
                [
                    'risk_level' => $highestRisk,
                    'reported_at' => now(),
                    'accumulated_rainfall' => $rainfall,
                    'flooded_polygon' => json_encode($floodData),
                ]
            );
        }
    }
}
