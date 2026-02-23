<?php

namespace App\Services;

use App\Jobs\ComputeCommunityFloodChiJob;
use App\Models\Barangay;
use App\Models\CommunityFloodReport;
use App\Models\CommunityFloodRoadStat;
use App\Models\Noah;
use App\Models\Weather;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommunityFloodReportingService
{
    /**
     * @return array{road: array, hazard_weight: float, rainfall: float, barangay_id: int|null, weather_id: int|null}
     */
    public function resolveInputsForPoint(float $lat, float $lng): array
    {
        $road = $this->findNearestRoadOrNull($lat, $lng);
        if ($road === null) {
            throw new \RuntimeException('No road found near the reported location.');
        }

        $hazardWeight = $this->hazardWeightAtPoint($lat, $lng);

        [$barangayId, $weatherId, $rainfall] = $this->rainfallAtPoint($lat, $lng);

        return [
            'road' => $road,
            'hazard_weight' => $hazardWeight,
            'rainfall' => $rainfall,
            'barangay_id' => $barangayId,
            'weather_id' => $weatherId,
        ];
    }

    /**
     * Create a community report and update road CHI stats.
     *
     * @return array{report: CommunityFloodReport, stat: CommunityFloodRoadStat, road: array}
     */
    public function createReport(User $user, float $lat, float $lng, int $estimatedDepth): array
    {
        $inputs = $this->resolveInputsForPoint($lat, $lng);
        $road = $inputs['road'];

        $tx = DB::transaction(function () use ($user, $lat, $lng, $inputs, $road, $estimatedDepth) {
            $segmentKey = $this->chooseSegmentKeyForReport(
                (int) ($road['gid'] ?? 0),
                is_int($road['segment_key'] ?? null) ? (int) $road['segment_key'] : null,
                (float) ($road['snapped_lat'] ?? $lat),
                (float) ($road['snapped_lng'] ?? $lng),
            );

            /** @var CommunityFloodReport $r */
            $r = CommunityFloodReport::create([
                'user_id' => $user->id,
                'road_gid' => (int) $road['gid'],
                'report_lat' => $lat,
                'report_lng' => $lng,
                'snapped_lat' => (float) $road['snapped_lat'],
                'snapped_lng' => (float) $road['snapped_lng'],
                'meters_away' => (float) $road['meters_away'],
                'road_line_fraction' => $road['road_line_fraction'],
                'segment_key' => $segmentKey,
                'barangay_id' => $inputs['barangay_id'],
                'weather_id' => $inputs['weather_id'],
                'hazard_weight' => (float) $inputs['hazard_weight'],
                'rainfall' => (float) $inputs['rainfall'],
                'estimated_depth' => $estimatedDepth,
                'chi_score' => 0.0,
                'risk_level' => 0,
            ]);

            [$chi, $risk, $count, $lastReportedAt, $centerLat, $centerLng, $avgDepth] = $this->computeChiForSegment((int) $road['gid'], $segmentKey);

            $stat = CommunityFloodRoadStat::updateOrCreate(
                ['road_gid' => (int) $road['gid'], 'segment_key' => $segmentKey],
                [
                    'center_lat' => $centerLat,
                    'center_lng' => $centerLng,
                    'chi_score' => $chi,
                    'risk_level' => $risk,
                    'reports_count' => $count,
                    'avg_estimated_depth' => $avgDepth,
                    'last_reported_at' => $lastReportedAt,
                ]
            );

            $r->update([
                'chi_score' => $chi,
                'risk_level' => $risk,
            ]);

            // Async recompute for eventual consistency under high write load.
            ComputeCommunityFloodChiJob::dispatch((int) $road['gid'], $segmentKey);

            return [
                'report' => $r,
                'segment_key' => $segmentKey,
            ];
        });

        /** @var array{report: CommunityFloodReport, segment_key: int} $tx */
        $report = $tx['report'];
        $segmentKey = (int) $tx['segment_key'];
        $stat = CommunityFloodRoadStat::query()
            ->where('road_gid', (int) $road['gid'])
            ->where('segment_key', $segmentKey)
            ->firstOrFail();

        return [
            'report' => $report,
            'stat' => $stat,
            'road' => $road,
        ];
    }

    private function chooseSegmentKeyForReport(int $roadGid, ?int $computedSegmentKey, float $snappedLat, float $snappedLng): int
    {
        // Goal: continuity. Drivers won't click the exact same coordinate.
        // If the snapped point is within ~binSize meters of an existing (recent) segment center for this road,
        // reuse that segment_key so nearby taps accumulate into the same segment.
        $binSizeM = (float) config('community_reporting.segment_bin_size_m', 100.0);
        $hours = (int) config('community_reporting.aggregation_window_hours', 24);
        $windowStart = now()->subHours(max(1, $hours));

        $distanceExpr = '(6371000 * acos(cos(radians(?)) * cos(radians(center_lat)) * cos(radians(center_lng) - radians(?)) + sin(radians(?)) * sin(radians(center_lat))))';

        $nearest = CommunityFloodRoadStat::query()
            ->where('road_gid', $roadGid)
            ->whereNotNull('center_lat')
            ->whereNotNull('center_lng')
            ->where('reports_count', '>', 0)
            ->where('last_reported_at', '>=', $windowStart)
            ->select(['segment_key'])
            ->selectRaw($distanceExpr . ' AS meters_away', [$snappedLat, $snappedLng, $snappedLat])
            ->whereRaw($distanceExpr . ' <= ?', [$snappedLat, $snappedLng, $snappedLat, $binSizeM])
            ->orderBy('meters_away')
            ->first();

        if ($nearest && is_numeric($nearest->segment_key ?? null)) {
            return (int) $nearest->segment_key;
        }

        return is_int($computedSegmentKey) ? $computedSegmentKey : 0;
    }

    private function findNearestRoadOrNull(float $lat, float $lng): ?array
    {
        $radiusM = (float) config('community_reporting.nearest_road_search_radius_m', 80.0);
        $binSizeM = (float) config('community_reporting.segment_bin_size_m', 100.0);
        $gis = DB::connection('gis_data');

        $sql = <<<'SQL'
WITH params AS (
  SELECT ST_SetSRID(ST_MakePoint(?, ?), 4326) AS pt
)
SELECT
  r.gid AS gid,
  COALESCE(NULLIF(r.name, ''), NULLIF(r.ref, ''), CONCAT('road_', r.gid::text)) AS name,
  r.ref AS ref,
  r.type AS type,
  ST_X(ST_ClosestPoint(ST_SetSRID(r.geom, 4326), (SELECT pt FROM params))) AS snapped_lng,
  ST_Y(ST_ClosestPoint(ST_SetSRID(r.geom, 4326), (SELECT pt FROM params))) AS snapped_lat,
    ST_Distance(ST_SetSRID(r.geom, 4326)::geography, (SELECT pt FROM params)::geography) AS meters_away,
    ST_LineLocatePoint(ST_SetSRID(r.geom, 4326), (SELECT pt FROM params)) AS road_line_fraction,
    ST_Length(ST_SetSRID(r.geom, 4326)::geography) AS road_length_m,
    FLOOR((ST_LineLocatePoint(ST_SetSRID(r.geom, 4326), (SELECT pt FROM params)) * ST_Length(ST_SetSRID(r.geom, 4326)::geography)) / ?)::int AS segment_key
FROM roads r
WHERE r.geom IS NOT NULL
  AND ST_DWithin(ST_SetSRID(r.geom, 4326)::geography, (SELECT pt FROM params)::geography, ?)
ORDER BY ST_SetSRID(r.geom, 4326) <-> (SELECT pt FROM params)
LIMIT 1;
SQL;

                $row = $gis->selectOne($sql, [$lng, $lat, $binSizeM, $radiusM]);
        if (!is_object($row)) {
            return null;
        }

        return [
            'gid' => (int) ($row->gid ?? 0),
            'name' => (string) ($row->name ?? ''),
            'ref' => (string) ($row->ref ?? ''),
            'type' => (string) ($row->type ?? ''),
            'snapped_lat' => is_numeric($row->snapped_lat ?? null) ? (float) $row->snapped_lat : $lat,
            'snapped_lng' => is_numeric($row->snapped_lng ?? null) ? (float) $row->snapped_lng : $lng,
            'meters_away' => is_numeric($row->meters_away ?? null) ? (float) $row->meters_away : null,
            'road_line_fraction' => is_numeric($row->road_line_fraction ?? null) ? (float) $row->road_line_fraction : null,
            'segment_key' => is_numeric($row->segment_key ?? null) ? (int) $row->segment_key : null,
        ];
    }

    private function hazardWeightAtPoint(float $lat, float $lng): float
    {
        $ptSql = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)';

        $row = Noah::on('gis_data')
            ->whereRaw('ST_Intersects(ST_SetSRID(geom, 4326), ' . $ptSql . ')', [$lng, $lat])
            ->selectRaw('COALESCE(MAX(var), 0) AS max_var')
            ->first();

        $val = is_object($row) ? ($row->max_var ?? 0) : 0;

        $var = is_numeric($val) ? (float) $val : 0.0;
        return (float) $this->hazardLevelFromNoahVar($var);
    }

    private function hazardLevelFromNoahVar(float $var): int
    {
        // The community-reporting CHI uses a hazard weight level (1/2/3).
        // In our data, `Noah.var` is used as the hazard multiplier in RWR.
        // Treat it as a continuous or discrete hazard indicator and map to 0..3.
        if ($var <= 0) {
            return 0;
        }

        // If var is already near 1..3, preserve that meaning.
        $lvl = (int) round($var);
        if ($lvl < 1) $lvl = 1;
        if ($lvl > 3) $lvl = 3;
        return $lvl;
    }

    /**
     * @return array{0:int|null,1:int|null,2:float}
     */
    private function rainfallAtPoint(float $lat, float $lng): array
    {
        // Preferred: determine barangay by administrative boundaries (gis_data.pampanga_boundary)
        // and then use the latest computed accumulated rainfall from `weathers.accumulated_rainfall`.
        $barangay = $this->barangayByBoundaryAtPoint($lat, $lng);

        // Fallback: if boundaries or name matching fails, approximate using nearest barangay centroid.
        if (!$barangay) {
            $barangay = Barangay::query()
                ->select(['id', 'name', 'city', 'province'])
                ->selectRaw(
                    '(6371000 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance_m',
                    [$lat, $lng, $lat]
                )
                ->orderBy('distance_m')
                ->first();
        }

        if (!$barangay) {
            return [null, null, 0.0];
        }

        $weather = Weather::query()
            ->where('barangay_id', $barangay->id)
            ->orderByDesc('fetched_at')
            ->first();
        if (!$weather) {
            return [(int) $barangay->id, null, 0.0];
        }

        $accum = is_numeric($weather->accumulated_rainfall ?? null) ? (float) $weather->accumulated_rainfall : 0.0;
        return [(int) $barangay->id, (int) $weather->id, $accum];
    }

    private function barangayByBoundaryAtPoint(float $lat, float $lng): ?Barangay
    {
        try {
            $gis = DB::connection('gis_data');

            $row = $gis->table('pampanga_boundary')
                ->whereRaw(
                    'ST_Contains(ST_SetSRID(geom, 4326), ST_SetSRID(ST_MakePoint(?, ?), 4326))',
                    [$lng, $lat]
                )
                ->select(['adm2_en', 'adm3_en', 'adm4_en'])
                ->first();

            if (!is_object($row)) {
                return null;
            }

            $province = is_string($row->adm2_en ?? null) ? trim((string) $row->adm2_en) : '';
            $city = is_string($row->adm3_en ?? null) ? trim((string) $row->adm3_en) : '';
            $name = is_string($row->adm4_en ?? null) ? trim((string) $row->adm4_en) : '';

            if ($province === '' || $city === '' || $name === '') {
                return null;
            }

            // Match loosely (ILIKE) because naming formats can differ (prefixes, casing, punctuation).
            return Barangay::query()
                ->whereRaw('LOWER(province) ILIKE ?', ['%' . strtolower($province) . '%'])
                ->whereRaw('LOWER(city) ILIKE ?', ['%' . strtolower($city) . '%'])
                ->whereRaw('LOWER(name) ILIKE ?', ['%' . strtolower($name) . '%'])
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{0:float,1:int,2:int,3:\Illuminate\Support\Carbon|null}
     */
    public function computeChiForSegment(int $roadGid, int $segmentKey): array
    {
        $hours = (int) config('community_reporting.aggregation_window_hours', 24);
        $windowStart = now()->subHours(max(1, $hours));

        $agg = CommunityFloodReport::query()
            ->where('road_gid', $roadGid)
            ->where('segment_key', $segmentKey)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('COUNT(*) AS reports_count, COALESCE(SUM(hazard_weight), 0) AS hazard_sum, COALESCE(MAX(rainfall), 0) AS rainfall, AVG(estimated_depth) AS avg_estimated_depth, MAX(created_at) AS last_reported_at')
            ->first();

        $count = (int) ($agg->reports_count ?? 0);
        $hazardSum = (float) ($agg->hazard_sum ?? 0.0);
        $rainfall = (float) ($agg->rainfall ?? 0.0);
        $avgDepth = is_numeric($agg->avg_estimated_depth ?? null) ? (float) $agg->avg_estimated_depth : null;

        // Community Hazard Index (CHI)
        // Spec: sum of hazard levels in the segment, multiplied by the barangay accumulated rainfall.
        // This intentionally increases as more drivers report the same segment.
        $chi = max(0.0, $hazardSum) * max(0.0, $rainfall);

        $thresholds = (array) config('community_reporting.chi_thresholds', []);
        $low = (float) ($thresholds['low'] ?? 201.0);
        $medium = (float) ($thresholds['medium'] ?? 351.0);
        // `high` in config is the first value considered HIGH, but the spec says HIGH is >550.
        // We keep the spec as the default behavior, while allowing override via env/config.
        $high = (float) ($thresholds['high'] ?? 551.0);

        $risk = 0;
        if ($chi >= $high) {
            $risk = 3;
        } elseif ($chi >= $medium) {
            $risk = 2;
        } elseif ($chi >= $low) {
            $risk = 1;
        }

        $center = CommunityFloodReport::query()
            ->where('road_gid', $roadGid)
            ->where('segment_key', $segmentKey)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('AVG(snapped_lat) AS center_lat, AVG(snapped_lng) AS center_lng')
            ->first();

        $centerLat = is_object($center) && is_numeric($center->center_lat ?? null) ? (float) $center->center_lat : null;
        $centerLng = is_object($center) && is_numeric($center->center_lng ?? null) ? (float) $center->center_lng : null;

        return [$chi, $risk, $count, $agg->last_reported_at ?? null, $centerLat, $centerLng, $avgDepth];
    }
}
