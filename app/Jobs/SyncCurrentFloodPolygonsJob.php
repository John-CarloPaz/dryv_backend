<?php

namespace App\Jobs;

use App\Models\FloodedGeometry;
use App\Models\Noah;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncCurrentFloodPolygonsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        try {
            // Build a gid -> max(risk_level) map from all FloodedGeometry records
            $gidRisk = [];

            foreach (FloodedGeometry::cursor() as $fg) {
                if (empty($fg->flooded_geojson)) {
                    continue;
                }

                $data = json_decode($fg->flooded_geojson, true);
                if (!is_array($data) || empty($data['features'])) {
                    continue;
                }

                foreach ($data['features'] as $feature) {
                    $props = $feature['properties'] ?? [];
                    if (!isset($props['gid'], $props['risk_level'])) {
                        continue;
                    }

                    $gid  = (int) $props['gid'];
                    $risk = (int) $props['risk_level'];

                    if (!array_key_exists($gid, $gidRisk) || $risk > $gidRisk[$gid]) {
                        $gidRisk[$gid] = $risk;
                    }
                }
            }

            $gis = DB::connection('gis_data');

            // Ensure the target table exists in gis_data
            $gis->statement(<<<SQL
                CREATE TABLE IF NOT EXISTS current_flood_polygons (
                    gid        integer PRIMARY KEY,
                    risk_level integer NOT NULL,
                    geom       geometry(Polygon, 4326) NOT NULL
                );
            SQL);

            // Rebuild the table from scratch so it always matches FloodedGeometry
            $gis->transaction(function () use ($gis, $gidRisk) {
                $gis->statement('TRUNCATE TABLE current_flood_polygons');

                foreach ($gidRisk as $gid => $riskLevel) {
                    // Insert geom from flood_map_exploded (Noah table) with the latest risk_level
                    $inserted = $gis->affectingStatement(
                        'INSERT INTO current_flood_polygons (gid, risk_level, geom)
                         SELECT gid, ?, ST_SetSRID(geom, 4326)
                         FROM flood_map_exploded
                         WHERE gid = ?',
                        [$riskLevel, $gid]
                    );

                    if ($inserted === 0) {
                        Log::warning('SyncCurrentFloodPolygonsJob: gid not found in flood_map_exploded', [
                            'gid' => $gid,
                        ]);
                    }
                }
            });

            Log::info('SyncCurrentFloodPolygonsJob: current_flood_polygons rebuilt', [
                'count' => count($gidRisk),
            ]);
        } catch (\Throwable $e) {
            Log::error('SyncCurrentFloodPolygonsJob: error rebuilding current_flood_polygons', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
