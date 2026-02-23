<?php

namespace App\Http\Controllers;

use App\Http\Requests\NearbyFloodCheckRequest;
use App\Models\FloodedGeometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FloodNearbyController extends Controller
{
    /**
     * Check if there are flooded areas/roads within 200 meters of a user point.
     *
     * Params (query): lat,lng (or Lat,Long)
     */
    public function nearby(NearbyFloodCheckRequest $request): JsonResponse
    {
        $data = $request->validated();

        $lat = array_key_exists('lat', $data) ? (float) $data['lat'] : (float) $data['Lat'];
        $lng = array_key_exists('lng', $data) ? (float) $data['lng'] : (float) $data['Long'];
        $radiusM = 200.0;

        $gis = DB::connection('gis_data');

        $lastUpdatedRaw = FloodedGeometry::query()->max('updated_at');
        $lastUpdatedAt = $lastUpdatedRaw ? Carbon::parse($lastUpdatedRaw)->toIso8601String() : null;

        try {
            $reg = $gis->selectOne("SELECT to_regclass('public.current_flood_polygons') AS reg");
            $tableExists = is_object($reg) && !empty($reg->reg);

            if (!$tableExists) {
                return response()->json([
                    'status' => 'ok',
                    'max_risk_level' => 0,
                    'flooded_roads' => [],
                'last_updated_at' => $lastUpdatedAt,
                ]);
            }

            $maxRow = $gis->selectOne(
              'SELECT COALESCE(MAX(risk_level), 0) AS max_risk_level '
              . 'FROM current_flood_polygons '
              . 'WHERE ST_DWithin(ST_SetSRID(geom, 4326)::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
              [$lng, $lat, $radiusM]
            );

            $maxRisk = (int) (is_object($maxRow) ? ($maxRow->max_risk_level ?? 0) : 0);

            $sql = <<<'SQL'
WITH params AS (
  SELECT ST_SetSRID(ST_MakePoint(?, ?), 4326) AS pt
),
floods AS (
  SELECT gid, risk_level, ST_SetSRID(geom, 4326) AS geom
  FROM current_flood_polygons
  WHERE ST_DWithin(ST_SetSRID(geom, 4326)::geography, (SELECT pt FROM params)::geography, ?)
),
road_hits AS (
  SELECT
    r.gid AS road_gid,
    COALESCE(NULLIF(r.name, ''), NULLIF(r.ref, ''), CONCAT('road_', r.gid::text)) AS road_name,
    r.type AS road_type,
    MAX(f.risk_level) AS risk_level,
    MIN(ST_Distance(ST_SetSRID(r.geom, 4326)::geography, (SELECT pt FROM params)::geography)) AS meters_away
  FROM roads r
  JOIN floods f
    ON ST_Intersects(ST_SetSRID(r.geom, 4326), f.geom)
  WHERE r.geom IS NOT NULL
    AND ST_DWithin(ST_SetSRID(r.geom, 4326)::geography, (SELECT pt FROM params)::geography, ?)
  GROUP BY r.gid, road_name, r.type
)
SELECT road_gid, road_name, road_type, risk_level, meters_away
FROM road_hits
ORDER BY risk_level DESC, meters_away ASC;
SQL;

            $rows = $gis->select($sql, [$lng, $lat, $radiusM, $radiusM]);

            $floodedRoads = array_map(function ($r) {
                $meters = is_object($r) ? ($r->meters_away ?? null) : null;
                $meters = is_numeric($meters) ? round((float) $meters, 2) : null;

                return [
                    'road_gid' => (int) (is_object($r) ? ($r->road_gid ?? 0) : 0),
                    'road_name' => (string) (is_object($r) ? ($r->road_name ?? '') : ''),
                    'road_type' => (string) (is_object($r) ? ($r->road_type ?? '') : ''),
                    'risk_level' => (int) (is_object($r) ? ($r->risk_level ?? 0) : 0),
                    'meters_away' => $meters,
                ];
            }, $rows);

            return response()->json([
                'status' => 'ok',
                'max_risk_level' => $maxRisk,
                'flooded_roads' => $floodedRoads,
              'last_updated_at' => $lastUpdatedAt,
            ]);
        } catch (\Throwable $e) {
            Log::error('FloodNearbyController: nearby flood check failed', [
                'error' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unexpected error while checking nearby floods.',
            ], 500);
        }
    }
}
