<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommunityFloodNearbyRequest;
use App\Http\Requests\CommunityFloodReportRequest;
use App\Http\Requests\CommunityFloodListRequest;
use App\Models\CommunityFloodRoadStat;
use App\Services\CommunityFloodReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommunityFloodReportController extends Controller
{
    private function depthLabel(?int $level): ?string
    {
        return match ($level) {
            1 => 'Ankle-Deep',
            2 => 'Knee-Deep',
            3 => 'Waist Deep',
            4 => 'Chest Deep',
            default => null,
        };
    }

    private function normalizeDepthLevel(?float $avg): ?int
    {
        if ($avg === null || !is_finite($avg)) {
            return null;
        }

        $lvl = (int) round($avg);
        if ($lvl < 1) $lvl = 1;
        if ($lvl > 4) $lvl = 4;
        return $lvl;
    }

    public function index(CommunityFloodListRequest $request): JsonResponse
    {
        $data = $request->validated();
        $minRisk = array_key_exists('min_risk_level', $data) ? (int) $data['min_risk_level'] : 1;

        $hours = (int) config('community_reporting.aggregation_window_hours', 24);
        $windowStart = now()->subHours(max(1, $hours));

        $stats = CommunityFloodRoadStat::query()
            ->where('risk_level', '>=', $minRisk)
            ->where('reports_count', '>', 0)
            ->where('last_reported_at', '>=', $windowStart)
            ->orderByDesc('risk_level')
            ->orderByDesc('chi_score')
            ->get();

        $roadGids = $stats->pluck('road_gid')->unique()->values()->all();

        $roadsByGid = [];
        if (!empty($roadGids)) {
            $rows = DB::connection('gis_data')
                ->table('roads')
                ->whereIn('gid', $roadGids)
                ->selectRaw("gid, COALESCE(NULLIF(name, ''), NULLIF(ref, ''), CONCAT('road_', gid::text)) AS road_name, type AS road_type, ref AS road_ref")
                ->get();

            foreach ($rows as $r) {
                $gid = is_object($r) && isset($r->gid) ? (int) $r->gid : null;
                if ($gid === null) {
                    continue;
                }

                $roadsByGid[$gid] = [
                    'gid' => $gid,
                    'name' => (string) ($r->road_name ?? ''),
                    'type' => (string) ($r->road_type ?? ''),
                    'ref' => (string) ($r->road_ref ?? ''),
                ];
            }
        }

        $items = $stats->map(function (CommunityFloodRoadStat $s) use ($roadsByGid) {
            $road = $roadsByGid[(int) ($s->road_gid ?? 0)] ?? null;

            $avgDepth = is_numeric($s->avg_estimated_depth ?? null) ? (float) $s->avg_estimated_depth : null;
            $avgDepthLevel = $this->normalizeDepthLevel($avgDepth);

            return [
                'flooded_road_lat' => is_numeric($s->center_lat ?? null) ? (float) $s->center_lat : null,
                'flooded_road_lng' => is_numeric($s->center_lng ?? null) ? (float) $s->center_lng : null,
                'road' => [
                    'gid' => (int) ($s->road_gid ?? 0),
                    'segment_key' => (int) ($s->segment_key ?? 0),
                    'name' => $road['name'] ?? '',
                    'type' => $road['type'] ?? '',
                    'ref' => $road['ref'] ?? '',
                ],
                'chi' => [
                    'score' => (float) ($s->chi_score ?? 0.0),
                    'risk_level' => (int) ($s->risk_level ?? 0),
                    'reports_count' => (int) ($s->reports_count ?? 0),
                    'last_reported_at' => $s->last_reported_at?->toIso8601String(),
                    'avg_estimated_depth' => $avgDepth,
                    'avg_estimated_depth_level' => $avgDepthLevel,
                    'avg_estimated_depth_label' => $this->depthLabel($avgDepthLevel),
                ],
            ];
        })->values()->all();

        $maxRisk = 0;
        foreach ($items as $it) {
            $maxRisk = max($maxRisk, (int) ($it['chi']['risk_level'] ?? 0));
        }

        return response()->json([
            'status' => 'ok',
            'max_risk_level' => $maxRisk,
            'window_hours' => $hours,
            'min_risk_level' => $minRisk,
            'community_flooded_roads' => array_values($items),
        ]);
    }

    public function nearby(CommunityFloodNearbyRequest $request): JsonResponse
    {
        $data = $request->validated();

        $lat = array_key_exists('lat', $data) ? (float) $data['lat'] : (float) $data['Lat'];
        $lng = array_key_exists('lng', $data) ? (float) $data['lng'] : (float) $data['Long'];

        $radiusM = array_key_exists('radius_m', $data) ? (float) $data['radius_m'] : 200.0;
        $limit = array_key_exists('limit', $data) ? (int) $data['limit'] : 50;

        $hours = (int) config('community_reporting.aggregation_window_hours', 24);
        $windowStart = now()->subHours(max(1, $hours));

        // Use a simple haversine distance on the stats center point.
        // This keeps the query on the app DB and avoids cross-db joins.
        $distanceExpr = '(6371000 * acos(cos(radians(?)) * cos(radians(center_lat)) * cos(radians(center_lng) - radians(?)) + sin(radians(?)) * sin(radians(center_lat))))';

        $stats = CommunityFloodRoadStat::query()
            ->whereNotNull('center_lat')
            ->whereNotNull('center_lng')
            ->where('reports_count', '>', 0)
            ->where('last_reported_at', '>=', $windowStart)
            ->select([
                'road_gid',
                'segment_key',
                'center_lat',
                'center_lng',
                'chi_score',
                'risk_level',
                'reports_count',
                'last_reported_at',
            ])
            ->selectRaw($distanceExpr . ' AS meters_away', [$lat, $lng, $lat])
            ->whereRaw($distanceExpr . ' <= ?', [$lat, $lng, $lat, $radiusM])
            ->orderByDesc('risk_level')
            ->orderBy('meters_away')
            ->limit($limit)
            ->get();

        $roadGids = $stats->pluck('road_gid')->unique()->values()->all();

        $roadsByGid = [];
        if (!empty($roadGids)) {
            $rows = DB::connection('gis_data')
                ->table('roads')
                ->whereIn('gid', $roadGids)
                ->selectRaw("gid, COALESCE(NULLIF(name, ''), NULLIF(ref, ''), CONCAT('road_', gid::text)) AS road_name, type AS road_type, ref AS road_ref")
                ->get();

            foreach ($rows as $r) {
                $gid = is_object($r) && isset($r->gid) ? (int) $r->gid : null;
                if ($gid === null) {
                    continue;
                }

                $roadsByGid[$gid] = [
                    'gid' => $gid,
                    'name' => (string) ($r->road_name ?? ''),
                    'type' => (string) ($r->road_type ?? ''),
                    'ref' => (string) ($r->road_ref ?? ''),
                ];
            }
        }

        $items = $stats->map(function (CommunityFloodRoadStat $s) use ($roadsByGid) {
            $road = $roadsByGid[(int) $s->road_gid] ?? null;
            $meters = is_numeric($s->meters_away ?? null) ? round((float) $s->meters_away, 2) : null;

            $avgDepth = is_numeric($s->avg_estimated_depth ?? null) ? (float) $s->avg_estimated_depth : null;
            $avgDepthLevel = $this->normalizeDepthLevel($avgDepth);

            return [
                'flooded_road_lat' => is_numeric($s->center_lat ?? null) ? (float) $s->center_lat : null,
                'flooded_road_lng' => is_numeric($s->center_lng ?? null) ? (float) $s->center_lng : null,
                'road' => [
                    'gid' => (int) $s->road_gid,
                    'segment_key' => (int) $s->segment_key,
                    'name' => $road['name'] ?? '',
                    'type' => $road['type'] ?? '',
                    'ref' => $road['ref'] ?? '',
                    'meters_away' => $meters,
                ],
                'chi' => [
                    'score' => (float) $s->chi_score,
                    'risk_level' => (int) $s->risk_level,
                    'reports_count' => (int) $s->reports_count,
                    'last_reported_at' => $s->last_reported_at?->toIso8601String(),
                    'avg_estimated_depth' => $avgDepth,
                    'avg_estimated_depth_level' => $avgDepthLevel,
                    'avg_estimated_depth_label' => $this->depthLabel($avgDepthLevel),
                ],
            ];
        })->values()->all();

        $maxRisk = 0;
        foreach ($items as $it) {
            $maxRisk = max($maxRisk, (int) ($it['chi']['risk_level'] ?? 0));
        }

        return response()->json([
            'status' => 'ok',
            'max_risk_level' => $maxRisk,
            'window_hours' => $hours,
            'community_flooded_roads' => $items,
        ]);
    }

    public function store(CommunityFloodReportRequest $request, CommunityFloodReportingService $service): JsonResponse
    {
        $data = $request->validated();

        $lat = array_key_exists('lat', $data) ? (float) $data['lat'] : (float) $data['Lat'];
        $lng = array_key_exists('lng', $data) ? (float) $data['lng'] : (float) $data['Long'];
        $estimatedDepth = (int) $data['estimated_depth'];

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $out = $service->createReport($user, $lat, $lng, $estimatedDepth);

            $report = $out['report'];
            $road = $out['road'];
            $stat = $out['stat'];

            $centerLat = is_numeric($stat->center_lat ?? null) ? (float) $stat->center_lat : null;
            $centerLng = is_numeric($stat->center_lng ?? null) ? (float) $stat->center_lng : null;

            return response()->json([
                'status' => 'ok',
                'flooded_road_lat' => $centerLat ?? (float) ($road['snapped_lat'] ?? $lat),
                'flooded_road_lng' => $centerLng ?? (float) ($road['snapped_lng'] ?? $lng),
                'road' => [
                    'gid' => (int) ($road['gid'] ?? 0),
                    'segment_key' => is_numeric($road['segment_key'] ?? null) ? (int) $road['segment_key'] : null,
                    'name' => (string) ($road['name'] ?? ''),
                    'type' => (string) ($road['type'] ?? ''),
                    'ref' => (string) ($road['ref'] ?? ''),
                    'meters_away' => $road['meters_away'],
                ],
                'chi' => [
                    'score' => (float) ($stat->chi_score ?? 0.0),
                    'risk_level' => (int) ($stat->risk_level ?? 0),
                    'reports_count' => (int) ($stat->reports_count ?? 0),
                    'center_lat' => $centerLat,
                    'center_lng' => $centerLng,
                    'window_hours' => (int) config('community_reporting.aggregation_window_hours', 24),
                    'avg_estimated_depth' => is_numeric($stat->avg_estimated_depth ?? null) ? (float) $stat->avg_estimated_depth : null,
                    'avg_estimated_depth_level' => $this->normalizeDepthLevel(is_numeric($stat->avg_estimated_depth ?? null) ? (float) $stat->avg_estimated_depth : null),
                    'avg_estimated_depth_label' => $this->depthLabel($this->normalizeDepthLevel(is_numeric($stat->avg_estimated_depth ?? null) ? (float) $stat->avg_estimated_depth : null)),
                ],
                'report' => [
                    'id' => (int) $report->id,
                    'user_id' => (int) $report->user_id,
                    'road_gid' => (int) $report->road_gid,
                    'segment_key' => is_numeric($report->segment_key ?? null) ? (int) $report->segment_key : null,
                    'report_lat' => (float) $report->report_lat,
                    'report_lng' => (float) $report->report_lng,
                    'hazard_weight' => (float) $report->hazard_weight,
                    'rainfall' => (float) $report->rainfall,
                    'estimated_depth' => is_numeric($report->estimated_depth ?? null) ? (int) $report->estimated_depth : null,
                    'estimated_depth_label' => $this->depthLabel(is_numeric($report->estimated_depth ?? null) ? (int) $report->estimated_depth : null),
                    'chi_score_at_submission' => (float) $report->chi_score,
                    'risk_level_at_submission' => (int) $report->risk_level,
                    'created_at' => $report->created_at?->toIso8601String(),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('CommunityFloodReportController: store failed', [
                'error' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
                'user_id' => $user->id ?? null,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unexpected error while creating flood report.',
            ], 500);
        }
    }
}
