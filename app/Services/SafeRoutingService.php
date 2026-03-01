<?php

namespace App\Services;

use App\Models\FloodedGeometry;
use App\Models\CommunityFloodRoadStat;
use App\Models\Noah;
use App\Models\Road;
use App\Services\RoadRoutingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SafeRoutingService
{
    private const DEFAULT_MAX_ATTEMPTS = 5;

    private RoadRoutingService $roadRoutingService;

    private ?string $roadsNameColumnCache = null;

    private function findNearestRoadAttributes(array $lngLat, float $radiusM = 80.0): ?array
    {
        if (!is_array($lngLat) || count($lngLat) < 2) {
            return null;
        }

        $lng = (float) $lngLat[0];
        $lat = (float) $lngLat[1];
        $radiusM = max(1.0, (float) $radiusM);

        try {
            $ptSql = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)';
            $row = DB::connection('gis_data')->selectOne(
                "select name, type, oneway, maxspeed\n" .
                "from roads\n" .
                "where ST_DWithin(geom::geography, {$ptSql}::geography, ?)\n" .
                "order by geom <-> {$ptSql}\n" .
                "limit 1",
                [$lng, $lat, $radiusM, $lng, $lat]
            );

            if (!$row) {
                return null;
            }

            return [
                'name' => isset($row->name) ? (string) $row->name : null,
                'type' => isset($row->type) ? (string) $row->type : null,
                'oneway' => isset($row->oneway) ? (int) $row->oneway : null,
                'maxspeed' => isset($row->maxspeed) ? (int) $row->maxspeed : null,
                'search_radius_m' => $radiusM,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'search_radius_m' => $radiusM,
            ];
        }
    }

    /**
     * Returns an array of per-point oneway flags (0/1) by snapping each point to its nearest road.
     * Points with no nearby road return null.
     *
     * @param array<int, array{0:float,1:float}> $lngLatPoints
     * @return array<int, int|null>|null
     */
    private function nearestRoadOnewayFlagsForPoints(array $lngLatPoints, float $radiusM, int $maxPoints): ?array
    {
        $pts = $this->normalizeLngLatPoints($lngLatPoints);
        if (count($pts) < 2) {
            return null;
        }
        if ($maxPoints > 0 && count($pts) > $maxPoints) {
            return null;
        }

        $radiusM = max(1.0, (float) $radiusM);

        $values = [];
        $bindings = [];
        foreach ($pts as $i => $p) {
            $values[] = '(?::int, ?::double precision, ?::double precision)';
            $bindings[] = (int) $i;
            $bindings[] = (float) $p[0];
            $bindings[] = (float) $p[1];
        }

        $valuesSql = implode(",\n", $values);

        $sql = <<<SQL
with pts(i, lng, lat) as (
    values
    {$valuesSql}
)
select pts.i as i,
       r.oneway as oneway
from pts
left join lateral (
    select oneway
    from roads
    where ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(pts.lng::double precision, pts.lat::double precision),4326)::geography, ?)
    order by geom <-> ST_SetSRID(ST_MakePoint(pts.lng::double precision, pts.lat::double precision),4326)
    limit 1
) r on true
order by pts.i asc
SQL;

        try {
            $rows = DB::connection('gis_data')->select($sql, array_merge($bindings, [$radiusM]));
        } catch (\Throwable $e) {
            Log::warning('SafeRoutingService: nearestRoadOnewayFlagsForPoints query failed', [
                'error' => $e->getMessage(),
                'point_count' => count($pts),
                'radius_m' => $radiusM,
            ]);
            return null;
        }

        $out = array_fill(0, count($pts), null);
        foreach ($rows as $row) {
            $idx = isset($row->i) ? (int) $row->i : null;
            if ($idx === null || $idx < 0 || $idx >= count($out)) {
                continue;
            }
            $out[$idx] = isset($row->oneway) ? (int) $row->oneway : null;
        }

        return $out;
    }

    /**
     * Apply a stable polyline offset with a per-vertex distance (meters).
     *
     * @param array<int, float> $metersPerPoint
     */
    private function applyVisualOffsetToGeometryVariable(array $geometry, array $metersPerPoint, string $side): array
    {
        if (($geometry['type'] ?? null) !== 'LineString') {
            return $geometry;
        }

        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            return $geometry;
        }

        $coords = $this->normalizeLngLatPoints($coords);
        $n = count($coords);
        if ($n < 2 || count($metersPerPoint) !== $n) {
            return $geometry;
        }

        $side = strtolower(trim($side));
        $rightSign = ($side === 'left') ? 1.0 : -1.0; // we compute LEFT normals; right is the opposite.

        $R = 6378137.0;
        $lat0Deg = (float) $coords[0][1];
        $lon0Deg = (float) $coords[0][0];
        $lat0 = deg2rad($lat0Deg);
        $lon0 = deg2rad($lon0Deg);
        $cosLat0 = cos($lat0);
        if (abs($cosLat0) < 1e-9) {
            $cosLat0 = 1e-9;
        }

        $xy = [];
        foreach ($coords as $pt) {
            $lat = deg2rad((float) $pt[1]);
            $lon = deg2rad((float) $pt[0]);
            $x = ($lon - $lon0) * $cosLat0 * $R;
            $y = ($lat - $lat0) * $R;
            $xy[] = [$x, $y];
        }

        $segNormals = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $dx = $xy[$i + 1][0] - $xy[$i][0];
            $dy = $xy[$i + 1][1] - $xy[$i][1];
            $len = sqrt(($dx * $dx) + ($dy * $dy));
            if ($len < 1e-6) {
                $segNormals[] = [0.0, 0.0];
                continue;
            }
            $nx = (-$dy) / $len;
            $ny = ($dx) / $len;
            $segNormals[] = [$nx, $ny];
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $m = (float) $metersPerPoint[$i];
            if ($m <= 0) {
                $out[] = $coords[$i];
                continue;
            }

            if ($i === 0) {
                $nx = $segNormals[0][0];
                $ny = $segNormals[0][1];
            } elseif ($i === $n - 1) {
                $nx = $segNormals[$n - 2][0];
                $ny = $segNormals[$n - 2][1];
            } else {
                $nx = $segNormals[$i - 1][0] + $segNormals[$i][0];
                $ny = $segNormals[$i - 1][1] + $segNormals[$i][1];
            }

            $norm = sqrt(($nx * $nx) + ($ny * $ny));
            if ($norm < 1e-9) {
                $fallback = ($i > 0) ? $segNormals[$i - 1] : $segNormals[0];
                $nx = $fallback[0];
                $ny = $fallback[1];
                $norm = sqrt(($nx * $nx) + ($ny * $ny));
            }
            if ($norm < 1e-9) {
                $out[] = $coords[$i];
                continue;
            }

            $nx /= $norm;
            $ny /= $norm;

            $x2 = $xy[$i][0] + ($rightSign * $m * $nx);
            $y2 = $xy[$i][1] + ($rightSign * $m * $ny);

            $lon2 = $lon0 + ($x2 / ($R * $cosLat0));
            $lat2 = $lat0 + ($y2 / $R);

            $out[] = [rad2deg($lon2), rad2deg($lat2)];
        }

        $geometry['coordinates'] = $out;
        return $geometry;
    }

    public function __construct(RoadRoutingService $roadRoutingService)
    {
        $this->roadRoutingService = $roadRoutingService;
    }

    public function findSafeRoute(array $origin, array $destination, ?string $routingProfile = null, ?string $vehicleType = null, array $exclude = [], ?int $maxAttempts = null, ?bool $avoidMotorway = null, bool $toggleCommunityReport = false): array
    {
        $clientProfile = $routingProfile ?? 'driving';
        $vehicleType = $vehicleType ?? 'car';
        $profile = $this->mapProfile($clientProfile);
        $maxAttempts = $maxAttempts ?? self::DEFAULT_MAX_ATTEMPTS;

        $exclude = is_array($exclude) ? $exclude : [];
        $exclude = array_values(array_unique(array_map(static fn ($v) => is_string($v) ? strtolower(trim($v)) : '', $exclude)));
        $exclude = array_values(array_filter($exclude, static fn ($v) => is_string($v) && $v !== ''));

        $effectiveAvoidMotorway = $avoidMotorway;
        if ($effectiveAvoidMotorway === null) {
            $effectiveAvoidMotorway = in_array('motorway', $exclude, true);
        }

        if ($effectiveAvoidMotorway === true && !in_array('motorway', $exclude, true)) {
            $exclude[] = 'motorway';
        }

        Log::info('SafeRoutingService: starting safe route computation', [
            'origin' => $origin,
            'destination' => $destination,
            'client_profile' => $clientProfile,
            'mapped_profile' => $profile,
            'vehicle_type' => $vehicleType,
            'exclude' => $exclude,
            'avoid_motorway' => $effectiveAvoidMotorway,
            'toggle_community_report' => $toggleCommunityReport,
            'max_attempts' => $maxAttempts,
            'engine' => config('safe_routing.engine'),
        ]);

        if (config('safe_routing.engine') === 'graph') {
            try {
                $t0 = microtime(true);

                $vehicleTypeNorm = strtolower($vehicleType ?: 'car');
                $profileNorm = strtolower($clientProfile ?: 'driving');

                $tSnap0 = microtime(true);
                [$startVertex, $endVertex] = $this->roadRoutingService->snapVertices($origin, $destination, $vehicleTypeNorm, $profileNorm, (bool) $effectiveAvoidMotorway);
                $tSnap1 = microtime(true);

                if ($startVertex === null || $endVertex === null) {
                    throw new RuntimeException('No road network found near origin or destination.');
                }

                // Optional: avoid community-reported flooded road segments.
                // Rule parity with flooded polygons:
                // - light vehicles (car/motor): allowed risk 1 -> avoid 2–3
                // - heavy (truck): allowed risk 1–2 -> avoid 3
                // - walking: avoid any risk > 0
                $avoidCommunityReport = (bool) $toggleCommunityReport;
                $blockedCommunitySegments = [];
                $communityMaxLastReportedAt = null;
                $communitySegmentsHash = 0;
                $communitySegmentBinSizeM = (float) config('community_reporting.segment_bin_size_m', 100.0);

                if ($avoidCommunityReport) {
                    $vehicleTypeForBudget = strtolower(trim((string) $vehicleTypeNorm));
                    $profileForBudget = strtolower(trim((string) $profileNorm));

                    $maxRiskBudget = match (true) {
                        $vehicleTypeForBudget === 'walking' || $profileForBudget === 'walking' => 0,
                        $vehicleTypeForBudget === 'truck' => 2,
                        default => 1,
                    };

                    $hours = (int) config('community_reporting.aggregation_window_hours', 24);
                    $windowStart = now()->subHours(max(1, $hours));

                    $rows = CommunityFloodRoadStat::query()
                        ->where('reports_count', '>', 0)
                        ->whereNotNull('last_reported_at')
                        ->where('last_reported_at', '>=', $windowStart)
                        ->where('risk_level', '>', $maxRiskBudget)
                        ->select(['road_gid', 'segment_key', 'last_reported_at'])
                        ->get();

                    foreach ($rows as $r) {
                        $roadGid = is_numeric($r->road_gid ?? null) ? (int) $r->road_gid : null;
                        $segmentKey = is_numeric($r->segment_key ?? null) ? (int) $r->segment_key : null;
                        if ($roadGid === null || $segmentKey === null) {
                            continue;
                        }

                        $blockedCommunitySegments[] = [
                            'road_gid' => $roadGid,
                            'segment_key' => $segmentKey,
                        ];

                        $ts = $r->last_reported_at ?? null;
                        if ($ts instanceof Carbon) {
                            $communityMaxLastReportedAt = ($communityMaxLastReportedAt === null)
                                ? $ts
                                : ($ts->greaterThan($communityMaxLastReportedAt) ? $ts : $communityMaxLastReportedAt);
                        }
                    }

                    // Stabilize ordering for consistent caching + DB input.
                    usort($blockedCommunitySegments, static function (array $a, array $b): int {
                        $aRoad = (int) ($a['road_gid'] ?? 0);
                        $bRoad = (int) ($b['road_gid'] ?? 0);
                        if ($aRoad !== $bRoad) {
                            return $aRoad <=> $bRoad;
                        }
                        return ((int) ($a['segment_key'] ?? 0)) <=> ((int) ($b['segment_key'] ?? 0));
                    });

                    $hashJson = json_encode($blockedCommunitySegments, JSON_UNESCAPED_SLASHES);
                    $communitySegmentsHash = is_string($hashJson) ? (int) crc32($hashJson) : 0;

                    // If nothing is currently blocked, treat as disabled.
                    if (count($blockedCommunitySegments) === 0) {
                        $avoidCommunityReport = false;
                    }
                }

                $ttlSeconds = (int) config('safe_routing.graph_cache_ttl_seconds', 0);
                $graphTol = (float) config('safe_routing.graph_simplify_tolerance_m', 2);
                $stepTol = (float) config('safe_routing.graph_step_simplify_tolerance_m', 1.5);
                $graphMinPts = (int) config('safe_routing.graph_simplify_min_points', 250);
                $mapMatchEnabled = (bool) config('safe_routing.graph_map_match_enabled', false);
                $mapMatchRadiusM = (float) config('safe_routing.graph_map_match_search_radius_m', 60);
                $mapMatchMaxPoints = (int) config('safe_routing.graph_map_match_max_points', 400);
                $visualOffsetM = (float) config('safe_routing.graph_visual_offset_m', 0);
                $visualOffsetSide = (string) config('safe_routing.graph_visual_offset_side', 'right');
                $visualOffsetMinorCapM = (float) config('safe_routing.graph_visual_offset_minor_cap_m', 3.0);
                $visualOffsetRoadRadiusM = (float) config('safe_routing.graph_visual_offset_nearest_road_radius_m', 80);
                $visualOffsetOnewayCapM = (float) config('safe_routing.graph_visual_offset_oneway_cap_m', 0.0);
                $visualSimplifyTolM = (float) config('safe_routing.graph_visual_simplify_tolerance_m', 0.6);

                $communityCount = $avoidCommunityReport ? count($blockedCommunitySegments) : 0;
                $communityMaxUnix = ($avoidCommunityReport && $communityMaxLastReportedAt instanceof Carbon)
                    ? (int) $communityMaxLastReportedAt->timestamp
                    : 0;
                $communityHash = $avoidCommunityReport ? (int) $communitySegmentsHash : 0;

                $cacheKey = sprintf(
                    'safe_route_graph:v18:%s:%s:%d:%d:am%d:gt%.2f:st%.2f:mm%d:r%.0f:vo%.1f:%s:voc%.1f:vo1c%.1f:vs%.2f:cr%d:crc%d:crm%d:crh%u',
                    $vehicleTypeNorm,
                    $profileNorm,
                    (int) $startVertex,
                    (int) $endVertex,
                    $effectiveAvoidMotorway ? 1 : 0,
                    $graphTol,
                    $stepTol,
                    $mapMatchEnabled ? 1 : 0,
                    $mapMatchRadiusM,
                    $visualOffsetM,
                    strtolower(trim($visualOffsetSide)) === 'left' ? 'L' : 'R',
                    $visualOffsetMinorCapM,
                    $visualOffsetOnewayCapM,
                    $visualSimplifyTolM,
                    $avoidCommunityReport ? 1 : 0,
                    $communityCount,
                    $communityMaxUnix,
                    $communityHash,
                );

                if ($ttlSeconds > 0) {
                    $tCacheGet0 = microtime(true);
                    $cached = Cache::get($cacheKey);
                    $tCacheGet1 = microtime(true);
                    if (is_array($cached)) {
                        $prevTimings = (isset($cached['_meta']['timings_ms']) && is_array($cached['_meta']['timings_ms']))
                            ? $cached['_meta']['timings_ms']
                            : [];

                        $cached = $this->refreshGraphResponseTimestampsAndUuid($cached);
                        $cached['_meta']['cache'] = [
                            'hit' => true,
                            'ttl_seconds' => $ttlSeconds,
                            'key' => $cacheKey,
                        ];
                        $cached['_meta']['timings_ms'] = [
                            'snap_vertices' => (int) round(($tSnap1 - $tSnap0) * 1000),
                            'cache_get' => (int) round(($tCacheGet1 - $tCacheGet0) * 1000),
                            'total' => (int) round((microtime(true) - $t0) * 1000),
                            'cached_compute_safe_route_geom' => $prevTimings['compute_safe_route_geom'] ?? null,
                            'cached_build_response' => $prevTimings['build_response'] ?? null,
                        ];

                        return $cached;
                    }
                }

                $tRoute0 = microtime(true);

                // Performance optimization: try routing within an expanding corridor first,
                // then fall back to full-graph routing to preserve correctness.
                $corridorList = config('safe_routing.graph_search_corridor_meters', [1500, 4000, 12000, 30000]);
                if (is_string($corridorList)) {
                    $corridorList = array_map('trim', explode(',', $corridorList));
                }
                if (!is_array($corridorList)) {
                    $corridorList = [1500, 4000, 12000, 30000];
                }

                $corridors = [];
                foreach ($corridorList as $v) {
                    if (is_numeric($v)) {
                        $m = (float) $v;
                        if (is_finite($m) && $m > 0) {
                            $corridors[] = $m;
                        }
                    }
                }
                $corridors = array_values(array_unique($corridors));
                sort($corridors);

                $attemptsUsed = 0;
                $route = null;
                $lastNoPath = null;

                $plannedCorridors = ($maxAttempts > 1)
                    ? array_slice($corridors, 0, max(0, $maxAttempts - 1))
                    : [];

                foreach ($plannedCorridors as $corridorM) {
                    $attemptsUsed++;
                    try {
                        $route = $this->roadRoutingService->computeSafeRouteByVertices(
                            (int) $startVertex,
                            (int) $endVertex,
                            $vehicleTypeNorm,
                            $profileNorm,
                            (bool) $effectiveAvoidMotorway,
                            (float) $corridorM,
                            $avoidCommunityReport,
                            $blockedCommunitySegments,
                            $communitySegmentBinSizeM,
                        );
                        break;
                    } catch (RuntimeException $e) {
                        $msg = strtolower($e->getMessage());
                        // Only retry on "no path". Other errors should bubble up.
                        if (str_contains($msg, 'no safe path') || str_contains($msg, 'timed out')) {
                            $lastNoPath = $e;
                            continue;
                        }
                        throw $e;
                    }
                }

                if ($route === null) {
                    $attemptsUsed++;
                    // Final attempt: full graph (no corridor) to preserve identical behavior.
                    $route = $this->roadRoutingService->computeSafeRouteByVertices(
                        (int) $startVertex,
                        (int) $endVertex,
                        $vehicleTypeNorm,
                        $profileNorm,
                        (bool) $effectiveAvoidMotorway,
                        null,
                        $avoidCommunityReport,
                        $blockedCommunitySegments,
                        $communitySegmentBinSizeM,
                    );
                }

                $tRoute1 = microtime(true);

                $tGeom0 = microtime(true);
                $geometryForClient = $this->computeGraphGeometryForClient(
                    $route['geometry'] ?? [],
                    [$origin['lng'], $origin['lat']],
                    [$destination['lng'], $destination['lat']],
                );

                $mapMatchMeta = null;
                if ($mapMatchEnabled) {
                    $mm = $this->mapMatchGeometryToNearestRoads($geometryForClient, $mapMatchRadiusM, $mapMatchMaxPoints);
                    if (is_array($mm)) {
                        $geometryForClient = $mm['geometry'] ?? $geometryForClient;
                        $mapMatchMeta = $mm['meta'] ?? null;
                    }
                }

                // Use the map-matched (but not visually offset) geometry for turn-by-turn generation.
                // A visual offset is only for rendering; it should not affect maneuvers or road-name lookup.
                $geometryForSteps = $geometryForClient;

                $geometryForRoute = $geometryForClient;

                $appliedOffsetMeta = null;
                if ($visualOffsetM !== 0.0) {
                    $side = strtolower(trim($visualOffsetSide));
                    $side = ($side === 'left') ? 'left' : 'right';
                    $requestedM = abs($visualOffsetM);
                    $policy = 'variable_by_oneway';

                    // Variable offset: for each vertex, look up nearest road and set offset to 0 for
                    // one-way roads (often already aligned to the correct carriageway), otherwise cap.
                    $coords = (($geometryForRoute['type'] ?? null) === 'LineString') ? ($geometryForRoute['coordinates'] ?? null) : null;
                    $coords = is_array($coords) ? $this->normalizeLngLatPoints($coords) : null;

                    $metersPerPoint = null;
                    $pointPolicyStats = null;
                    $appliedSummary = null;

                    if (is_array($coords) && count($coords) >= 2) {
                        $flags = $this->nearestRoadOnewayFlagsForPoints($coords, $visualOffsetRoadRadiusM, (int) config('safe_routing.graph_map_match_max_points', 400));
                        if (is_array($flags) && count($flags) === count($coords)) {
                            $metersPerPoint = [];
                            $zeroed = 0;
                            $capped = 0;
                            $minApplied = null;
                            $maxApplied = null;
                            foreach ($flags as $f) {
                                $m = $requestedM;
                                if ($f === 1) {
                                    $m = max(0.0, (float) $visualOffsetOnewayCapM);
                                    if ($m <= 0) {
                                        $zeroed++;
                                    }
                                } else {
                                    if ($visualOffsetMinorCapM > 0) {
                                        $m2 = min($m, (float) $visualOffsetMinorCapM);
                                        if ($m2 < $m) {
                                            $capped++;
                                        }
                                        $m = $m2;
                                    }
                                }
                                $metersPerPoint[] = (float) $m;

                                $minApplied = ($minApplied === null) ? $m : min($minApplied, $m);
                                $maxApplied = ($maxApplied === null) ? $m : max($maxApplied, $m);
                            }

                            $pointPolicyStats = [
                                'point_count' => count($coords),
                                'zeroed_points' => $zeroed,
                                'capped_points' => $capped,
                                'minor_cap_m' => $visualOffsetMinorCapM,
                                'oneway_cap_m' => $visualOffsetOnewayCapM,
                            ];

                            $appliedSummary = [
                                'min_m' => $minApplied,
                                'max_m' => $maxApplied,
                            ];
                        }
                    }

                    if (is_array($metersPerPoint)) {
                        $geometryForRoute = $this->applyVisualOffsetToGeometryVariable($geometryForRoute, $metersPerPoint, $side);
                    } else {
                        // Fallback: apply a single capped value.
                        $appliedM = $requestedM;
                        if ($visualOffsetMinorCapM > 0) {
                            $appliedM = min($appliedM, $visualOffsetMinorCapM);
                        }
                        if ($appliedM > 0) {
                            $geometryForRoute = $this->applyVisualOffsetToGeometry($geometryForRoute, $appliedM, $side);
                        }

                        $appliedSummary = [
                            'min_m' => $appliedM,
                            'max_m' => $appliedM,
                        ];
                    }

                    // Render-only cleanup: remove micro-zigzags after offset.
                    if ($visualSimplifyTolM > 0) {
                        $geometryForRoute = $this->simplifyGeometry($geometryForRoute, $visualSimplifyTolM);
                    }

                    $appliedOffsetMeta = [
                        'enabled' => true,
                        'meters' => $requestedM,
                        'side' => $side,
                        'requested_meters' => $requestedM,
                        'policy' => $policy,
                        'per_point' => $pointPolicyStats,
                        'applied_meters' => $appliedSummary,
                    ];
                }

                if ($graphTol > 0 && $this->countGeometryPoints($geometryForRoute) >= $graphMinPts) {
                    $geometryForRoute = $this->simplifyGeometry($geometryForRoute, $graphTol);
                }
                $tGeom1 = microtime(true);

                Log::info('SafeRoutingService: graph engine route computed', [
                    'distance_m' => $route['distance_m'] ?? null,
                    'max_risk_level' => $route['max_risk_level'] ?? null,
                    'geometry_type' => $geometryForRoute['type'] ?? ($route['geometry']['type'] ?? null),
                ]);

                $tBuild0 = microtime(true);
                $response = $this->buildGraphDirectionsLikeResponse(
                    $geometryForRoute,
                    $route['distance_m'] ?? null,
                    $origin,
                    $destination,
                    $clientProfile,
                    $vehicleType,
                    $route['max_risk_level'] ?? null,
                    $geometryForSteps,
                    $route['duration_s'] ?? null,
                    $attemptsUsed,
                );

                if ($mapMatchMeta !== null) {
                    $response['_meta']['map_match'] = $mapMatchMeta;
                }

                if ($appliedOffsetMeta !== null) {
                    $response['_meta']['visual_offset'] = $appliedOffsetMeta;
                }

                $tBuild1 = microtime(true);

                $response['_meta']['timings_ms'] = array_merge(
                    $response['_meta']['timings_ms'] ?? [],
                    [
                        'snap_vertices' => (int) round(($tSnap1 - $tSnap0) * 1000),
                        'compute_safe_route_geom' => (int) round(($tRoute1 - $tRoute0) * 1000),
                        'geometry_postprocess' => (int) round(($tGeom1 - $tGeom0) * 1000),
                        'build_response' => (int) round(($tBuild1 - $tBuild0) * 1000),
                        'total' => (int) round((microtime(true) - $t0) * 1000),
                    ]
                );

                if ($ttlSeconds > 0) {
                    Cache::put($cacheKey, $response, $ttlSeconds);
                    $response['_meta']['cache'] = [
                        'hit' => false,
                        'ttl_seconds' => $ttlSeconds,
                        'key' => $cacheKey,
                    ];
                }

                return $response;
            } catch (\Throwable $e) {
                Log::error('SafeRoutingService: graph-based routing failed', [
                    'error' => $e->getMessage(),
                    'avoid_motorway' => $effectiveAvoidMotorway,
                ]);

                // No Mapbox fallback.
                throw $e;
            }
        }

        // Mapbox routing has been disabled; only graph routing is supported.
        throw new RuntimeException('Safe routing fallback is disabled. Configure the graph engine to compute routes.');
    }

    /**
     * Derive up to 20 waypoints from a graph MultiLineString geometry
     * for use with Mapbox Navigation.
     */
    private function computeGraphWaypoints(array $geometry, int $maxWaypoints = 20, ?array $originLngLat = null, ?array $destinationLngLat = null): array
    {
        $type = $geometry['type'] ?? null;
        if ($type === 'LineString') {
            $points = $geometry['coordinates'] ?? [];
            if (!is_array($points) || empty($points)) {
                return [];
            }

            return $this->sampleWaypointsFromPoints($points, $maxWaypoints);
        }

        if ($type !== 'MultiLineString') {
            return [];
        }

        $segments = $geometry['coordinates'] ?? [];
        if (!is_array($segments) || empty($segments)) {
            return [];
        }

        // If we don't have a single connected LineString, derive waypoints from the
        // longest connected chain of segments (no "bridging" across gaps).
        $ordered = $this->orderMultiLineStringSegments($segments, $originLngLat);
        $connectedPoints = $this->flattenConnectedSegmentsToPoints($ordered['connected_segments'] ?? []);
        if (empty($connectedPoints)) {
            Log::warning('SafeRoutingService: unable to derive graph waypoints (disconnected geometry)');
            return [];
        }

        return $this->sampleWaypointsFromPoints($connectedPoints, $maxWaypoints);
    }

    /**
     * Prepare graph route geometry for frontend rendering without creating "bridge" lines.
     * - If the MultiLineString segments are fully connected, returns a LineString.
     * - Otherwise returns an ordered MultiLineString (so the client draws gaps rather than
     *   drawing straight lines through buildings).
     */
    private function computeGraphGeometryForClient(array $geometry, ?array $originLngLat = null, ?array $destinationLngLat = null): array
    {
        $type = $geometry['type'] ?? null;
        if ($type === 'LineString') {
            return $geometry;
        }

        if ($type !== 'MultiLineString') {
            return $geometry;
        }

        $segments = $geometry['coordinates'] ?? [];
        if (!is_array($segments) || empty($segments)) {
            return $geometry;
        }

        $ordered = $this->orderMultiLineStringSegments($segments, $originLngLat);

        $connectedSegments = $ordered['connected_segments'] ?? [];
        $remainingSegments = $ordered['remaining_segments'] ?? [];
        $isFullyConnected = ($ordered['is_fully_connected'] ?? false) === true;

        if ($isFullyConnected && !empty($connectedSegments)) {
            $points = $this->flattenConnectedSegmentsToPoints($connectedSegments);

            // Ensure direction is origin -> destination if we can.
            if (
                is_array($originLngLat) && count($originLngLat) >= 2 &&
                is_array($destinationLngLat) && count($destinationLngLat) >= 2 &&
                count($points) >= 2
            ) {
                $dStartToOrigin = $this->distanceSq($points[0], $originLngLat);
                $dEndToOrigin = $this->distanceSq($points[count($points) - 1], $originLngLat);
                $dStartToDest = $this->distanceSq($points[0], $destinationLngLat);
                $dEndToDest = $this->distanceSq($points[count($points) - 1], $destinationLngLat);

                // Prefer the orientation where start is closest to origin and end is closest to destination.
                $scoreForward = $dStartToOrigin + $dEndToDest;
                $scoreReverse = $dStartToDest + $dEndToOrigin;
                if ($scoreReverse < $scoreForward) {
                    $points = array_reverse($points);
                }
            }

            return [
                'type' => 'LineString',
                'coordinates' => $points,
            ];
        }

        // Not fully connected: return ordered MultiLineString to avoid fake bridges.
        Log::warning('SafeRoutingService: graph route geometry is disconnected; likely topology tolerance issue (pgr_createTopology too large)', [
            'connected_segment_count' => is_array($connectedSegments) ? count($connectedSegments) : 0,
            'remaining_segment_count' => is_array($remainingSegments) ? count($remainingSegments) : 0,
        ]);

        return [
            'type' => 'MultiLineString',
            'coordinates' => array_values(array_merge($connectedSegments, $remainingSegments)),
        ];
    }

    private function sampleWaypointsFromPoints(array $points, int $maxWaypoints): array
    {
        $count = count($points);
        if ($count === 0) {
            return [];
        }

        if ($count <= $maxWaypoints) {
            return array_map(static function ($pt) {
                return ['lng' => $pt[0], 'lat' => $pt[1]];
            }, $points);
        }

        $indices = [0];
        $step = ($count - 1) / ($maxWaypoints - 1);
        $lastIndex = 0;

        for ($i = 1; $i < $maxWaypoints - 1; $i++) {
            $idx = (int) round($i * $step);
            if ($idx <= $lastIndex) {
                $idx = $lastIndex + 1;
            }
            if ($idx >= $count - 1) {
                break;
            }
            $indices[] = $idx;
            $lastIndex = $idx;
        }

        if ($lastIndex < $count - 1) {
            $indices[] = $count - 1;
        }

        $indices = array_values(array_unique($indices));

        $waypoints = [];
        foreach ($indices as $idx) {
            $pt = $points[$idx];
            $waypoints[] = ['lng' => $pt[0], 'lat' => $pt[1]];
        }

        return $waypoints;
    }

    /**
     * Order MultiLineString segments by chaining exact-ish endpoint matches.
     * Returns the longest connected chain + any remaining segments (unconnected).
     */
    private function orderMultiLineStringSegments(array $segments, ?array $originLngLat = null): array
    {
        $cleanSegments = [];
        foreach ($segments as $segment) {
            if (!is_array($segment) || count($segment) < 2) {
                continue;
            }

            $clean = [];
            foreach ($segment as $pt) {
                if (!is_array($pt) || count($pt) < 2) {
                    continue;
                }
                $clean[] = [(float) $pt[0], (float) $pt[1]];
            }

            if (count($clean) >= 2) {
                $cleanSegments[] = $clean;
            }
        }

        if (empty($cleanSegments)) {
            return [
                'connected_segments' => [],
                'remaining_segments' => [],
                'is_fully_connected' => false,
            ];
        }

        // Choose start segment closest to origin if provided.
        $startIndex = 0;
        $startReverse = false;
        if (is_array($originLngLat) && count($originLngLat) >= 2) {
            $best = null;
            foreach ($cleanSegments as $i => $seg) {
                $a = $seg[0];
                $b = $seg[count($seg) - 1];
                $da = $this->distanceSq($a, $originLngLat);
                $db = $this->distanceSq($b, $originLngLat);
                $d = min($da, $db);
                if ($best === null || $d < $best['d']) {
                    $best = ['i' => $i, 'd' => $d, 'reverse' => ($db < $da)];
                }
            }
            if ($best !== null) {
                $startIndex = $best['i'];
                $startReverse = $best['reverse'];
            }
        }

        $used = array_fill(0, count($cleanSegments), false);
        $current = $cleanSegments[$startIndex];
        if ($startReverse) {
            $current = array_reverse($current);
        }
        $used[$startIndex] = true;

        $connected = [$current];
        $last = $current[count($current) - 1];

        $toleranceSq = 1e-10; // ~1e-5 degrees tolerance

        while (in_array(false, $used, true)) {
            $found = null;
            foreach ($cleanSegments as $i => $seg) {
                if ($used[$i]) {
                    continue;
                }
                $a = $seg[0];
                $b = $seg[count($seg) - 1];
                if ($this->distanceSq($a, $last) <= $toleranceSq) {
                    $found = ['i' => $i, 'reverse' => false];
                    break;
                }
                if ($this->distanceSq($b, $last) <= $toleranceSq) {
                    $found = ['i' => $i, 'reverse' => true];
                    break;
                }
            }

            if ($found === null) {
                break;
            }

            $next = $cleanSegments[$found['i']];
            if ($found['reverse']) {
                $next = array_reverse($next);
            }

            // Avoid duplicating shared vertex between segments.
            if ($this->distanceSq($next[0], $last) <= $toleranceSq) {
                array_shift($next);
            }

            $connected[] = $next;
            $last = $next[count($next) - 1];
            $used[$found['i']] = true;
        }

        $remaining = [];
        foreach ($cleanSegments as $i => $seg) {
            if (!$used[$i]) {
                $remaining[] = $seg;
            }
        }

        return [
            'connected_segments' => $connected,
            'remaining_segments' => $remaining,
            'is_fully_connected' => empty($remaining),
        ];
    }

    private function flattenConnectedSegmentsToPoints(array $segments): array
    {
        $points = [];
        foreach ($segments as $seg) {
            if (!is_array($seg) || count($seg) < 2) {
                continue;
            }
            foreach ($seg as $pt) {
                if (!is_array($pt) || count($pt) < 2) {
                    continue;
                }
                $points[] = [$pt[0], $pt[1]];
            }
        }
        return $points;
    }

    private function distanceSq(array $a, array $b): float
    {
        $dx = ((float) $a[0]) - ((float) $b[0]);
        $dy = ((float) $a[1]) - ((float) $b[1]);
        return ($dx * $dx) + ($dy * $dy);
    }

    private function mapProfile(string $profile): string
    {
        return match ($profile) {
            'traffic' => 'driving-traffic',
            'walking' => 'walking',
            'cycling' => 'cycling',
            default => 'driving',
        };
    }

    private function callMapboxDirections(array $coords, string $profile, array $exclude): array
    {
        $token = config('services.mapbox.token');
        if (empty($token)) {
            throw new RuntimeException('MAPBOX_ACCESS_TOKEN is not configured.');
        }

        $coordsString = collect($coords)
            ->map(fn ($c) => implode(',', $c))
            ->implode(';');

        $query = [
            'geometries' => 'geojson',
            'overview' => 'full',
            'steps' => 'true',
            'access_token' => $token,
        ];

        if (!empty($exclude)) {
            $query['exclude'] = implode(',', $exclude);
        }

        $url = sprintf('https://api.mapbox.com/directions/v5/mapbox/%s/%s', $profile, $coordsString);

        Log::info('SafeRoutingService: calling Mapbox Directions', [
            'profile' => $profile,
            'exclude' => $exclude,
        ]);

        $response = Http::timeout(30)
            ->retry(2, 500)
            ->get($url, $query);

        if (!$response->successful()) {
            Log::warning('SafeRoutingService: Mapbox responded with error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Mapbox Directions API error: ' . $response->status());
        }

        $data = $response->json();

        if (empty($data['routes'])) {
            Log::warning('SafeRoutingService: Mapbox returned no routes');
            throw new RuntimeException('Mapbox returned no routes.');
        }

        Log::info('SafeRoutingService: Mapbox returned routes', [
            'route_count' => count($data['routes']),
            'status' => $response->status(),
        ]);

        return $data;
    }

    /**
     * Build a Mapbox-like directions payload from our own graph route geometry.
     * This avoids any external Directions provider while keeping client compatibility.
     */
    private function buildGraphDirectionsLikeResponse(
        array $routeGeometryForClient,
        ?float $distanceM,
        array $origin,
        array $destination,
        string $clientProfile,
        string $vehicleType,
        ?int $maxRiskLevel,
        ?array $stepsGeometryForClient = null,
        ?float $durationSFromGraph = null,
        int $attempts = 1
    ): array {
        $mode = match (strtolower($clientProfile)) {
            'walking' => 'walking',
            'cycling' => 'cycling',
            default => 'driving',
        };

        $durationS = (is_numeric($durationSFromGraph) && (float) $durationSFromGraph > 0)
            ? (float) $durationSFromGraph
            : null;

        $speedMps = $this->estimateSpeedMps($mode, $vehicleType);
        if ($mode === 'driving' && $durationS !== null && $distanceM !== null && $durationS > 0) {
            // Use the DB-computed duration to derive an average speed so step durations match ETA.
            $derived = $distanceM / $durationS;
            if (is_finite($derived) && $derived > 0) {
                // Guardrails: avoid absurd speeds from bad maxspeed data.
                $speedMps = max(1.0, min(55.0, (float) $derived));
            }
        }

        if ($durationS === null) {
            $durationS = ($distanceM !== null && $speedMps > 0) ? ($distanceM / $speedMps) : null;
        }

        $stepsGeometry = is_array($stepsGeometryForClient) ? $stepsGeometryForClient : $routeGeometryForClient;

        $steps = $this->generateTurnByTurnStepsFromGeometry(
            $stepsGeometry,
            [(float) $origin['lng'], (float) $origin['lat']],
            [(float) $destination['lng'], (float) $destination['lat']],
            $mode,
            $speedMps,
        );

        $stepToleranceM = (float) config('safe_routing.graph_step_simplify_tolerance_m', 1.5);
        $stepMinPts = (int) config('safe_routing.graph_step_simplify_min_points', 25);
        if ($stepToleranceM > 0) {
            $steps = $this->simplifyStepGeometries($steps, $stepToleranceM, $stepMinPts);
        }

        $filledNames = 0;
        foreach ($steps as $s) {
            $nm = $s['name'] ?? '';
            if (is_string($nm) && trim($nm) !== '') {
                $filledNames++;
            }
        }

        $risk = $this->mapRiskLevel($maxRiskLevel);
        $confidence = $this->estimateGraphConfidence($stepsGeometry, $steps);
        $rerouteReason = $this->buildRerouteReason($maxRiskLevel);

        $etaAt = null;
        if ($durationS !== null) {
            try {
                $etaAt = Carbon::now()->addSeconds((int) round($durationS))->toIso8601String();
            } catch (\Throwable $e) {
                $etaAt = null;
            }
        }

        return [
            'routes' => [
                [
                    'geometry' => $routeGeometryForClient,
                    'distance' => $distanceM,
                    'weight' => $distanceM,
                    'duration' => $durationS,
                    'legs' => [
                        [
                            'summary' => 'graph',
                            'distance' => $distanceM,
                            'duration' => $durationS,
                            'steps' => $steps,
                        ],
                    ],
                ],
            ],
            'waypoints' => [
                ['name' => '', 'location' => [(float) $origin['lng'], (float) $origin['lat']]],
                ['name' => '', 'location' => [(float) $destination['lng'], (float) $destination['lat']]],
            ],
            'code' => 'Ok',
            'uuid' => (string) Str::uuid(),
            '_meta' => [
                'engine' => 'graph',
                'attempts' => max(1, (int) $attempts),
                'max_risk_level' => $maxRiskLevel,
                'note' => 'Turn-by-turn steps generated by internal graph engine',
                'risk_level' => $risk,
                'confidence' => $confidence,
                'reroute_reason' => $rerouteReason,
                'computed_at' => Carbon::now()->toIso8601String(),
                'eta_at' => $etaAt,
                'road_name_enrichment' => [
                    'filled' => $filledNames,
                    'total_steps' => count($steps),
                ],
            ],
        ];
    }

    private function refreshGraphResponseTimestampsAndUuid(array $route): array
    {
        // Refresh fields that should reflect request time.
        $route['uuid'] = (string) Str::uuid();

        if (!isset($route['_meta']) || !is_array($route['_meta'])) {
            $route['_meta'] = [];
        }

        $route['_meta']['computed_at'] = Carbon::now()->toIso8601String();

        $durationS = Arr::get($route, 'routes.0.duration');
        if (is_numeric($durationS)) {
            $route['_meta']['eta_at'] = Carbon::now()->addSeconds((int) round((float) $durationS))->toIso8601String();
        }

        return $route;
    }

    private function estimateSpeedMps(string $mode, string $vehicleType): float
    {
        $mode = strtolower($mode);
        if ($mode === 'walking') {
            return 1.4; // ~5 km/h
        }
        if ($mode === 'cycling') {
            return 4.2; // ~15 km/h
        }

        // Driving: keep conservative defaults.
        $vehicleType = strtolower($vehicleType);
        return match ($vehicleType) {
            'motor' => 11.1, // ~40 km/h
            default => 13.9, // ~50 km/h
        };
    }

    /**
     * Generate coarse turn-by-turn steps from a (Multi)LineString by detecting bearing changes.
     */
    private function generateTurnByTurnStepsFromGeometry(
        array $geometry,
        array $originLngLat,
        array $destinationLngLat,
        string $mode,
        float $speedMps
    ): array {
        $type = $geometry['type'] ?? null;
        $segments = [];

        if ($type === 'LineString') {
            $coords = $geometry['coordinates'] ?? [];
            if (is_array($coords) && count($coords) >= 2) {
                $coords = $this->normalizeLngLatPoints($coords);
                $coords = $this->ensureEndpoints($coords, $originLngLat, $destinationLngLat);
                $segments[] = $coords;
            }
        } elseif ($type === 'MultiLineString') {
            $multi = $geometry['coordinates'] ?? [];
            if (is_array($multi)) {
                foreach ($multi as $seg) {
                    if (is_array($seg) && count($seg) >= 2) {
                        $seg = $this->normalizeLngLatPoints($seg);
                        $segments[] = $seg;
                    }
                }
            }
        }

        if (empty($segments)) {
            return [];
        }

        $steps = [];
        $isFirstSegment = true;

        foreach ($segments as $idx => $points) {
            if (count($points) < 2) {
                continue;
            }

            // Only force exact origin/destination on first/last connected pieces.
            if ($idx === 0) {
                $points = $this->ensureStartPoint($points, $originLngLat);
            }
            if ($idx === count($segments) - 1) {
                $points = $this->ensureEndPoint($points, $destinationLngLat);
            }

            $segmentSteps = $this->generateStepsFromPoints($points, $mode, $speedMps, $isFirstSegment);
            foreach ($segmentSteps as $s) {
                $steps[] = $s;
            }
            $isFirstSegment = false;
        }

        // Enrich step names using the road network, if available.
        $steps = $this->populateStepRoadNames($steps);

        // Reduce UX-noise:
        // - downgrade tiny-angle "turn" maneuvers into "continue"
        // - merge consecutive micro-steps on the same road
        $steps = $this->normalizeAndMergeSteps($steps);

        // Ensure final arrive maneuver exists for UX consistency.
        $last = end($steps);
        $dest = $destinationLngLat;
        if ($last === false || ($last['maneuver']['type'] ?? null) !== 'arrive') {
            $steps[] = [
                'distance' => 0.0,
                'duration' => 0.0,
                'geometry' => ['type' => 'LineString', 'coordinates' => [$dest, $dest]],
                'name' => '',
                'mode' => $mode,
                'maneuver' => [
                    'type' => 'arrive',
                    'instruction' => 'Arrive at your destination',
                    'location' => $dest,
                    'bearing_before' => null,
                    'bearing_after' => null,
                    'modifier' => 'destination',
                ],
                'intersections' => [
                    [
                        'location' => $dest,
                        'bearings' => [],
                        'entry' => [],
                    ],
                ],
            ];
        } else {
            // If the last step is already arrive, ensure it has a destination modifier.
            $lastIndex = count($steps) - 1;
            if ($lastIndex >= 0 && isset($steps[$lastIndex]['maneuver']) && is_array($steps[$lastIndex]['maneuver'])) {
                $steps[$lastIndex]['maneuver']['modifier'] = $steps[$lastIndex]['maneuver']['modifier'] ?? 'destination';
            }
        }

        return $steps;
    }

    private function normalizeAndMergeSteps(array $steps): array
    {
        if (count($steps) < 2) {
            return $steps;
        }

        // First pass: normalize false turns.
        foreach ($steps as $i => $step) {
            $steps[$i] = $this->downgradeFalseTurnToContinue($step);
        }

        // Second pass: merge consecutive same-road micro steps.
        $out = [];
        $i = 0;
        while ($i < count($steps)) {
            $cur = $steps[$i];
            $next = ($i + 1 < count($steps)) ? $steps[$i + 1] : null;

            if ($next !== null && $this->shouldMergeSteps($cur, $next)) {
                $merged = $this->mergeTwoSteps($cur, $next);

                // Also allow chaining multiple merges (A+B+C...).
                $j = $i + 2;
                while ($j < count($steps) && $this->shouldMergeSteps($merged, $steps[$j])) {
                    $merged = $this->mergeTwoSteps($merged, $steps[$j]);
                    $j++;
                }

                $out[] = $merged;
                $i = $j;
                continue;
            }

            $out[] = $cur;
            $i++;
        }

        return $out;
    }

    private function downgradeFalseTurnToContinue(array $step): array
    {
        $maneuver = $step['maneuver'] ?? null;
        if (!is_array($maneuver)) {
            return $step;
        }
        if (($maneuver['type'] ?? null) !== 'turn') {
            return $step;
        }

        $bb = $maneuver['bearing_before'] ?? null;
        $ba = $maneuver['bearing_after'] ?? null;
        if (!is_numeric($bb) || !is_numeric($ba)) {
            return $step;
        }

        $delta = abs($this->normalizeAngleDeg(((float) $ba) - ((float) $bb)));
        // UX rule: < 20° is not a turn.
        if ($delta >= 20.0) {
            return $step;
        }

        $roadName = is_string($step['name'] ?? null) ? (string) $step['name'] : null;

        $step['maneuver']['type'] = 'continue';
        unset($step['maneuver']['modifier']);
        $step['maneuver']['instruction'] = $this->instructionForManeuver(
            'continue',
            null,
            is_numeric($ba) ? (float) $ba : null,
            $roadName,
        );

        return $step;
    }

    private function shouldMergeSteps(array $a, array $b): bool
    {
        $aType = $a['maneuver']['type'] ?? null;
        $bType = $b['maneuver']['type'] ?? null;
        if (!is_string($aType) || !is_string($bType)) {
            return false;
        }

        // Never merge finish.
        if ($aType === 'arrive' || $bType === 'arrive') {
            return false;
        }

        $aName = is_string($a['name'] ?? null) ? trim((string) $a['name']) : '';
        $bName = is_string($b['name'] ?? null) ? trim((string) $b['name']) : '';
        if ($aName === '' || $bName === '' || strcasecmp($aName, $bName) !== 0) {
            return false;
        }

        $bDist = isset($b['distance']) ? (float) $b['distance'] : null;
        $aDist = isset($a['distance']) ? (float) $a['distance'] : null;
        if ($bDist === null || $aDist === null) {
            return false;
        }

        // Only merge short follow-up steps (micro steps).
        if ($bDist > 90.0) {
            return false;
        }

        // If the second step isn't a real decision point (continue or tiny-angle turn), merge it.
        if ($bType === 'continue') {
            return true;
        }

        if ($bType !== 'turn') {
            return false;
        }

        $bb = $b['maneuver']['bearing_before'] ?? null;
        $ba = $b['maneuver']['bearing_after'] ?? null;
        if (is_numeric($bb) && is_numeric($ba)) {
            $delta = abs($this->normalizeAngleDeg(((float) $ba) - ((float) $bb)));
            if ($delta < 25.0) {
                return true;
            }
        }

        // Two consecutive turns on the same road are usually noisy; merge when short.
        return $bDist < 45.0;
    }

    private function mergeTwoSteps(array $a, array $b): array
    {
        $aGeom = $a['geometry']['coordinates'] ?? [];
        $bGeom = $b['geometry']['coordinates'] ?? [];

        if (is_array($aGeom) && is_array($bGeom) && !empty($aGeom) && !empty($bGeom)) {
            $lastA = $aGeom[count($aGeom) - 1];
            $firstB = $bGeom[0];
            if (is_array($lastA) && is_array($firstB) && count($lastA) >= 2 && count($firstB) >= 2) {
                if (abs(((float) $lastA[0]) - ((float) $firstB[0])) < 1e-10 && abs(((float) $lastA[1]) - ((float) $firstB[1])) < 1e-10) {
                    array_shift($bGeom);
                }
            }
            $a['geometry']['coordinates'] = array_values(array_merge($aGeom, $bGeom));
        }

        $a['distance'] = ((float) ($a['distance'] ?? 0)) + ((float) ($b['distance'] ?? 0));
        $a['duration'] = ((float) ($a['duration'] ?? 0)) + ((float) ($b['duration'] ?? 0));

        // Keep the first maneuver (it represents the decision), but update bearing_after to the latest.
        if (isset($b['maneuver']['bearing_after']) && is_numeric($b['maneuver']['bearing_after'])) {
            $a['maneuver']['bearing_after'] = (float) $b['maneuver']['bearing_after'];
        }

        // Rebuild intersections using updated bearings.
        $loc = $a['maneuver']['location'] ?? null;
        $a['intersections'] = $this->buildIntersections(
            is_array($loc) ? $loc : null,
            is_numeric($a['maneuver']['bearing_before'] ?? null) ? (float) $a['maneuver']['bearing_before'] : null,
            is_numeric($a['maneuver']['bearing_after'] ?? null) ? (float) $a['maneuver']['bearing_after'] : null,
        );

        // Keep instruction consistent with road name.
        $roadName = is_string($a['name'] ?? null) ? (string) $a['name'] : null;
        $type = is_string($a['maneuver']['type'] ?? null) ? (string) $a['maneuver']['type'] : 'continue';
        $modifier = is_string($a['maneuver']['modifier'] ?? null) ? (string) $a['maneuver']['modifier'] : null;
        $bearingAfter = is_numeric($a['maneuver']['bearing_after'] ?? null) ? (float) $a['maneuver']['bearing_after'] : null;
        $a['maneuver']['instruction'] = $this->instructionForManeuver($type, $modifier, $bearingAfter, $roadName);

        return $a;
    }

    /**
     * Populate each step's 'name' from the nearest road segment in the `roads` table.
     */
    private function populateStepRoadNames(array $steps): array
    {
        if (empty($steps)) {
            return $steps;
        }

        $locations = [];
        foreach ($steps as $idx => $step) {
            $loc = $step['maneuver']['location'] ?? null;
            if (!is_array($loc) || count($loc) < 2) {
                continue;
            }

            $locations[] = [
                'idx' => (int) $idx,
                'lng' => (float) $loc[0],
                'lat' => (float) $loc[1],
            ];
        }

        if (empty($locations)) {
            return $steps;
        }

        try {
            $namesByIdx = $this->lookupNearestRoadNames($locations);
        } catch (\Throwable $e) {
            Log::warning('SafeRoutingService: road name lookup failed', [
                'error' => $e->getMessage(),
            ]);
            return $steps;
        }

        foreach ($namesByIdx as $idx => $name) {
            if (!isset($steps[$idx])) {
                continue;
            }
            $steps[$idx]['name'] = $name;

            // Improve instruction readability when we have a street name.
            $type = $steps[$idx]['maneuver']['type'] ?? null;
            if (!empty($name) && is_string($type)) {
                $steps[$idx]['maneuver']['instruction'] = $this->instructionForManeuver(
                    $type,
                    $steps[$idx]['maneuver']['modifier'] ?? null,
                    $steps[$idx]['maneuver']['bearing_after'] ?? null,
                    $name,
                );
            }
        }

        return $steps;
    }

    /**
     * @param array<int, array{idx:int,lng:float,lat:float}> $locations
     * @return array<int, string> map of step index -> road name
     */
    private function lookupNearestRoadNames(array $locations): array
    {
        $nameColumn = $this->getRoadNameColumn();
        $fallbackColumn = $this->getRoadFallbackNameColumn();

        // Build a VALUES table: (idx, lng, lat), ...
        $placeholders = [];
        $bindings = [];
        foreach ($locations as $loc) {
            $placeholders[] = '(?, ?, ?)';
            $bindings[] = (int) $loc['idx'];
            $bindings[] = (float) $loc['lng'];
            $bindings[] = (float) $loc['lat'];
        }

        // Bound VALUES parameters may be treated as text by Postgres; cast before ST_MakePoint.
                $pointExpr = 'ST_SetSRID(ST_MakePoint((pts.lng)::double precision, (pts.lat)::double precision), 4326)';

                // Prefer non-empty strings, not just non-null.
                $roadNameExpr = 'COALESCE(NULLIF(BTRIM(' . $nameColumn . '), \'\'), NULLIF(BTRIM(' . $fallbackColumn . '), \'\'))';
                $roadNameNonEmptyExpr = '(' . $roadNameExpr . ') IS NOT NULL';

                // Use an index-friendly KNN (<->) search on geometry.
                // Radius is approximate degrees (~meters/111km). Slightly generous to avoid false negatives.
                $searchRadiusM = 250; // meters
                $radiusDeg = $searchRadiusM / 111000.0;

                $sql = 'WITH pts(idx, lng, lat) AS (VALUES ' . implode(', ', $placeholders) . ')
                                SELECT pts.idx,
                                             COALESCE(NULLIF(BTRIM(r_named.name), \'\'), NULLIF(BTRIM(r_any.name), \'\'), \'\') AS name
                                FROM pts
                                LEFT JOIN LATERAL (
                                        SELECT ' . $roadNameExpr . ' AS name
                                        FROM roads
                                        WHERE geom IS NOT NULL
                                            AND ' . $roadNameNonEmptyExpr . '
                                            AND geom && ST_Expand(' . $pointExpr . ', ' . $radiusDeg . ')
                                            AND ST_DWithin(geom, ' . $pointExpr . ', ' . $radiusDeg . ')
                                        ORDER BY geom <-> ' . $pointExpr . '
                                        LIMIT 1
                                ) r_named ON true
                                LEFT JOIN LATERAL (
                                        SELECT ' . $roadNameExpr . ' AS name
                                        FROM roads
                                        WHERE geom IS NOT NULL
                                        ORDER BY geom <-> ' . $pointExpr . '
                                        LIMIT 1
                                ) r_any ON true';

        $rows = DB::connection('gis_data')->select($sql, $bindings);

        $out = [];
        foreach ($rows as $row) {
            $idx = isset($row->idx) ? (int) $row->idx : null;
            if ($idx === null) {
                continue;
            }
            $out[$idx] = isset($row->name) ? (string) $row->name : '';
        }

        return $out;
    }

    private function getRoadFallbackNameColumn(): string
    {
        // Currently, roads has `ref` in addition to `name`; prefer name but allow fallback.
        // Keep this whitelisted because it gets interpolated.
        return 'ref';
    }

    /**
     * Find a suitable road name column on the `roads` table.
     * Keeps a small whitelist to avoid SQL injection since this is interpolated.
     */
    private function getRoadNameColumn(): string
    {
        if ($this->roadsNameColumnCache !== null) {
            return $this->roadsNameColumnCache;
        }

        $candidates = [
            'name',
            'road_name',
            'street',
            'ref',
            'fullname',
        ];

        try {
            $rows = DB::connection('gis_data')->select(
                "SELECT column_name
                 FROM information_schema.columns
                 WHERE table_name = 'roads'",
            );
        } catch (\Throwable $e) {
            // Fall back to the most common column.
            $this->roadsNameColumnCache = 'name';
            return $this->roadsNameColumnCache;
        }

        $existing = [];
        foreach ($rows as $row) {
            if (isset($row->column_name)) {
                $existing[strtolower((string) $row->column_name)] = true;
            }
        }

        foreach ($candidates as $col) {
            if (isset($existing[strtolower($col)])) {
                $this->roadsNameColumnCache = $col;
                return $this->roadsNameColumnCache;
            }
        }

        $this->roadsNameColumnCache = 'name';
        return $this->roadsNameColumnCache;
    }

    private function ensureEndpoints(array $points, array $originLngLat, array $destinationLngLat): array
    {
        $points = $this->ensureStartPoint($points, $originLngLat);
        $points = $this->ensureEndPoint($points, $destinationLngLat);
        return $points;
    }

    private function ensureStartPoint(array $points, array $originLngLat): array
    {
        if (count($points) < 1) {
            return $points;
        }
        $thresholdM = 25.0;
        $d = $this->haversineDistanceM($points[0], $originLngLat);
        if ($d > $thresholdM) {
            array_unshift($points, $originLngLat);
        }
        return $points;
    }

    private function ensureEndPoint(array $points, array $destinationLngLat): array
    {
        if (count($points) < 1) {
            return $points;
        }
        $thresholdM = 25.0;
        $d = $this->haversineDistanceM($points[count($points) - 1], $destinationLngLat);
        if ($d > $thresholdM) {
            $points[] = $destinationLngLat;
        }
        return $points;
    }

    private function normalizeLngLatPoints(array $points): array
    {
        $out = [];
        foreach ($points as $pt) {
            if (!is_array($pt) || count($pt) < 2) {
                continue;
            }
            $out[] = [(float) $pt[0], (float) $pt[1]];
        }
        return $out;
    }

    private function generateStepsFromPoints(array $points, string $mode, float $speedMps, bool $isFirstSegment): array
    {
        $n = count($points);
        if ($n < 2) {
            return [];
        }

        // Angle change -> maneuver generation:
        // <20° ignore (wiggles), 20–45° slight, 45–135° turn, >135° sharp / u-turn
        $TURN_THRESHOLD_DEG = 20.0;
        $MIN_STEP_DISTANCE_M = 20.0;

        $steps = [];
        $startIndex = 0;
        $incomingBearing = null;
        $pendingManeuverType = $isFirstSegment ? 'depart' : 'continue';
        $pendingModifier = null;

        $currentBearing = $this->bearingDeg($points[0], $points[1]);

        for ($i = 1; $i < $n - 1; $i++) {
            $nextBearing = $this->bearingDeg($points[$i], $points[$i + 1]);
            $deltaSigned = $this->normalizeAngleDeg($nextBearing - $currentBearing);
            $deltaAbs = abs($deltaSigned);

            $distFromStart = $this->polylineDistanceM(array_slice($points, $startIndex, ($i - $startIndex + 1)));

            if ($deltaAbs >= $TURN_THRESHOLD_DEG && $distFromStart >= $MIN_STEP_DISTANCE_M) {
                // Close current step at vertex i.
                $steps[] = $this->buildStep(
                    array_slice($points, $startIndex, ($i - $startIndex + 1)),
                    $mode,
                    $speedMps,
                    $pendingManeuverType,
                    $incomingBearing,
                    $currentBearing,
                    $pendingModifier,
                );

                // Next step starts at i with a turn maneuver.
                $startIndex = $i;
                $incomingBearing = $currentBearing;
                $pendingManeuverType = 'turn';
                $pendingModifier = $this->bearingToTurnModifier($deltaSigned, $deltaAbs);
                $currentBearing = $nextBearing;
                continue;
            }

            $currentBearing = $nextBearing;
        }

        // Final step to the end.
        if ($startIndex < $n - 1) {
            $finalBearing = $this->bearingDeg($points[$startIndex], $points[$startIndex + 1]);
            $steps[] = $this->buildStep(
                array_slice($points, $startIndex),
                $mode,
                $speedMps,
                $pendingManeuverType,
                $incomingBearing,
                $finalBearing,
                $pendingModifier,
            );
        }

        return $steps;
    }

    private function buildStep(
        array $stepPoints,
        string $mode,
        float $speedMps,
        string $maneuverType,
        ?float $bearingBefore,
        ?float $bearingAfter,
        ?string $modifier
    ): array {
        $rawPoints = $this->normalizeLngLatPoints($stepPoints);
        $distanceM = $this->polylineDistanceM($rawPoints);
        $durationS = ($speedMps > 0) ? ($distanceM / $speedMps) : null;
        $location = $rawPoints[0] ?? null;

        $instruction = $this->instructionForManeuver($maneuverType, $modifier, $bearingAfter, null);

        $maneuver = [
            'type' => $maneuverType,
            'instruction' => $instruction,
            'location' => $location,
            'bearing_before' => $bearingBefore,
            'bearing_after' => $bearingAfter,
        ];
        if ($modifier !== null) {
            $maneuver['modifier'] = $modifier;
        }

        $intersections = $this->buildIntersections($location, $bearingBefore, $bearingAfter);

        return [
            'distance' => $distanceM,
            'duration' => $durationS,
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $rawPoints,
            ],
            'name' => '',
            'mode' => $mode,
            'maneuver' => $maneuver,
            'intersections' => $intersections,
        ];
    }

    private function buildIntersections(?array $locationLngLat, ?float $bearingBefore, ?float $bearingAfter): array
    {
        if (!is_array($locationLngLat) || count($locationLngLat) < 2) {
            return [];
        }

        $bearings = [];
        $entry = [];

        if ($bearingBefore !== null) {
            $bearings[] = (int) round($bearingBefore);
            $entry[] = true;
        }
        if ($bearingAfter !== null) {
            $bearings[] = (int) round($bearingAfter);
            $entry[] = true;
        }

        return [
            [
                'location' => $locationLngLat,
                'bearings' => $bearings,
                'entry' => $entry,
            ],
        ];
    }

    private function mapRiskLevel(?int $maxRiskLevel): string
    {
        $lvl = $maxRiskLevel ?? 0;
        return match (true) {
            $lvl <= 1 => 'low',
            $lvl === 2 => 'medium',
            default => 'high',
        };
    }

    private function buildRerouteReason(?int $maxRiskLevel): string
    {
        if ($maxRiskLevel === null) {
            return 'Flood-aware routing enabled';
        }

        return match (true) {
            $maxRiskLevel <= 1 => 'Flood-aware routing enabled',
            $maxRiskLevel === 2 => 'Some moderate-risk segments may remain',
            default => 'High-risk segments avoided where possible',
        };
    }

    private function estimateGraphConfidence(array $geometryForClient, array $steps): float
    {
        // Heuristic confidence: connected LineString routes with reasonable steps score higher.
        $type = $geometryForClient['type'] ?? null;
        $geomScore = ($type === 'LineString') ? 1.0 : 0.6;

        $stepCount = count($steps);
        $stepScore = 1.0;
        if ($stepCount <= 1) {
            $stepScore = 0.6;
        } elseif ($stepCount > 60) {
            $stepScore = 0.7;
        }

        $score = 0.7 * $geomScore + 0.3 * $stepScore;
        if ($score < 0.0) {
            return 0.0;
        }
        if ($score > 1.0) {
            return 1.0;
        }
        return round($score, 2);
    }

    private function simplifyStepGeometries(array $steps, float $toleranceM, int $minPoints = 0): array
    {
        foreach ($steps as $i => $step) {
            $geom = $step['geometry'] ?? null;
            if (!is_array($geom) || ($geom['type'] ?? null) !== 'LineString') {
                continue;
            }

            $coords = $geom['coordinates'] ?? null;
            if (!is_array($coords) || count($coords) < 3) {
                continue;
            }

            if ($minPoints > 0 && count($coords) < $minPoints) {
                continue;
            }

            $simp = $this->simplifyLineString($coords, $toleranceM);
            if (count($simp) >= 2) {
                $steps[$i]['geometry']['coordinates'] = $simp;
            }
        }

        return $steps;
    }

    private function countGeometryPoints(array $geometry): int
    {
        $type = $geometry['type'] ?? null;
        if ($type === 'LineString') {
            $coordinates = $geometry['coordinates'] ?? null;
            return is_array($coordinates) ? count($coordinates) : 0;
        }

        if ($type === 'MultiLineString') {
            $coordinates = $geometry['coordinates'] ?? null;
            if (!is_array($coordinates)) {
                return 0;
            }

            $count = 0;
            foreach ($coordinates as $line) {
                if (is_array($line)) {
                    $count += count($line);
                }
            }
            return $count;
        }

        return 0;
    }

    /**
     * Map-match a LineString geometry to the nearest road geometries for better visual alignment.
     * Returns ['geometry' => <GeoJSON geometry array>, 'meta' => <diagnostics>].
     */
    private function mapMatchGeometryToNearestRoads(array $geometry, float $searchRadiusM, int $maxPoints): ?array
    {
        if (($geometry['type'] ?? null) !== 'LineString') {
            return null;
        }

        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            return null;
        }

        if ($maxPoints > 0 && count($coords) > $maxPoints) {
            return [
                'geometry' => $geometry,
                'meta' => [
                    'enabled' => true,
                    'skipped' => true,
                    'reason' => 'too_many_points',
                    'point_count' => count($coords),
                    'max_points' => $maxPoints,
                    'search_radius_m' => $searchRadiusM,
                ],
            ];
        }

        $coords = $this->normalizeLngLatPoints($coords);
        if (count($coords) < 2) {
            return null;
        }

        // Build a VALUES table so Postgres can snap each point in a single query.
        $valuesSql = [];
        $bindings = [];
        foreach ($coords as $i => $pt) {
            if (!is_array($pt) || count($pt) < 2) {
                continue;
            }
            $valuesSql[] = '(?, ?, ?)';
            $bindings[] = $i + 1;
            $bindings[] = (float) $pt[0];
            $bindings[] = (float) $pt[1];
        }

        if (empty($valuesSql)) {
            return null;
        }

        $bindings[] = (float) $searchRadiusM;

        $sql = "
WITH input_points AS (
  SELECT v.ord::int AS ord,
         ST_SetSRID(ST_MakePoint((v.lon)::double precision, (v.lat)::double precision), 4326) AS pt
  FROM (VALUES " . implode(',', $valuesSql) . ") AS v(ord, lon, lat)
),
snapped AS (
  SELECT p.ord,
         p.pt AS origpt,
         COALESCE(
           (
                         SELECT ST_ClosestPoint(r.geom, p.pt)
                         FROM roads r
             WHERE r.geom IS NOT NULL
               AND ST_DWithin(r.geom::geography, p.pt::geography, ?)
             ORDER BY r.geom <-> p.pt
             LIMIT 1
           ),
           p.pt
         ) AS snappt
  FROM input_points p
),
line AS (
  SELECT ST_MakeLine(snappt ORDER BY ord) AS geom,
         AVG(ST_Distance(origpt::geography, snappt::geography)) AS avg_m,
         MAX(ST_Distance(origpt::geography, snappt::geography)) AS max_m
  FROM snapped
)
SELECT ST_AsGeoJSON(geom) AS geojson, avg_m, max_m
FROM line
";

        try {
            $gis = DB::connection('gis_data');
            $timeoutMs = (int) config('safe_routing.graph_db_statement_timeout_ms', 0);
            if ($timeoutMs > 0) {
                $row = $gis->transaction(function () use ($gis, $sql, $bindings, $timeoutMs) {
                    $gis->statement('SET LOCAL statement_timeout = ' . (int) $timeoutMs);
                    return $gis->selectOne($sql, $bindings);
                });
            } else {
                $row = $gis->selectOne($sql, $bindings);
            }
        } catch (\Throwable $e) {
            Log::warning('SafeRoutingService: map-match query failed; returning original geometry', [
                'error' => $e->getMessage(),
            ]);
            return [
                'geometry' => $geometry,
                'meta' => [
                    'enabled' => true,
                    'skipped' => true,
                    'reason' => 'query_failed',
                    'search_radius_m' => $searchRadiusM,
                ],
            ];
        }

        $geojson = is_object($row) ? ($row->geojson ?? null) : (is_array($row) ? ($row['geojson'] ?? null) : null);
        if (!is_string($geojson) || $geojson === '') {
            return [
                'geometry' => $geometry,
                'meta' => [
                    'enabled' => true,
                    'skipped' => true,
                    'reason' => 'no_geojson',
                    'search_radius_m' => $searchRadiusM,
                ],
            ];
        }

        $decoded = json_decode($geojson, true);
        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'LineString') {
            return [
                'geometry' => $geometry,
                'meta' => [
                    'enabled' => true,
                    'skipped' => true,
                    'reason' => 'invalid_geojson',
                    'search_radius_m' => $searchRadiusM,
                ],
            ];
        }

        $avgM = is_object($row) ? ($row->avg_m ?? null) : (is_array($row) ? ($row['avg_m'] ?? null) : null);
        $maxM = is_object($row) ? ($row->max_m ?? null) : (is_array($row) ? ($row['max_m'] ?? null) : null);

        return [
            'geometry' => $decoded,
            'meta' => [
                'enabled' => true,
                'skipped' => false,
                'search_radius_m' => $searchRadiusM,
                'point_count' => count($coords),
                'avg_snap_distance_m' => $avgM !== null ? (float) $avgM : null,
                'max_snap_distance_m' => $maxM !== null ? (float) $maxM : null,
            ],
        ];
    }

    private function applyVisualOffsetToGeometry(array $geometry, float $meters, string $side): array
    {
        if ($meters <= 0) {
            return $geometry;
        }

        if (($geometry['type'] ?? null) !== 'LineString') {
            return $geometry;
        }

        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            return $geometry;
        }

        $coords = $this->normalizeLngLatPoints($coords);
        $n = count($coords);
        if ($n < 2) {
            return $geometry;
        }

        $side = strtolower(trim($side));
        // Prefer a true polyline offset (parallel curve) rather than per-vertex bearing offset.
        // Per-vertex perpendiculars can create sideways wobble on straight roads and odd kinks
        // at joins; averaging segment normals yields a much more stable visual lane shift.
        $rightSign = ($side === 'left') ? 1.0 : -1.0; // we compute LEFT normals; right is the opposite.

        $R = 6378137.0;
        $lat0Deg = (float) $coords[0][1];
        $lon0Deg = (float) $coords[0][0];
        $lat0 = deg2rad($lat0Deg);
        $lon0 = deg2rad($lon0Deg);
        $cosLat0 = cos($lat0);
        if (abs($cosLat0) < 1e-9) {
            $cosLat0 = 1e-9;
        }

        $xy = [];
        foreach ($coords as $pt) {
            $lat = deg2rad((float) $pt[1]);
            $lon = deg2rad((float) $pt[0]);
            $x = ($lon - $lon0) * $cosLat0 * $R;
            $y = ($lat - $lat0) * $R;
            $xy[] = [$x, $y];
        }

        $segNormals = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $dx = $xy[$i + 1][0] - $xy[$i][0];
            $dy = $xy[$i + 1][1] - $xy[$i][1];
            $len = sqrt(($dx * $dx) + ($dy * $dy));
            if ($len < 1e-6) {
                $segNormals[] = [0.0, 0.0];
                continue;
            }
            // Left normal of segment direction.
            $nx = (-$dy) / $len;
            $ny = ($dx) / $len;
            $segNormals[] = [$nx, $ny];
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $nx = $segNormals[0][0];
                $ny = $segNormals[0][1];
            } elseif ($i === $n - 1) {
                $nx = $segNormals[$n - 2][0];
                $ny = $segNormals[$n - 2][1];
            } else {
                $nx = $segNormals[$i - 1][0] + $segNormals[$i][0];
                $ny = $segNormals[$i - 1][1] + $segNormals[$i][1];
            }

            $norm = sqrt(($nx * $nx) + ($ny * $ny));
            if ($norm < 1e-9) {
                // Degenerate: fall back to a nearby segment normal.
                $fallback = ($i > 0) ? $segNormals[$i - 1] : $segNormals[0];
                $nx = $fallback[0];
                $ny = $fallback[1];
                $norm = sqrt(($nx * $nx) + ($ny * $ny));
            }
            if ($norm < 1e-9) {
                $out[] = $coords[$i];
                continue;
            }

            $nx /= $norm;
            $ny /= $norm;

            $x2 = $xy[$i][0] + ($rightSign * $meters * $nx);
            $y2 = $xy[$i][1] + ($rightSign * $meters * $ny);

            $lon2 = $lon0 + ($x2 / ($R * $cosLat0));
            $lat2 = $lat0 + ($y2 / $R);

            $out[] = [rad2deg($lon2), rad2deg($lat2)];
        }

        $geometry['coordinates'] = $out;
        return $geometry;
    }

    /**
     * Offsets [lng,lat] by `meters` along `bearingDeg`.
     * Uses a small-distance spherical approximation.
     *
     * @param array{0:float,1:float} $lngLat
     * @return array{0:float,1:float}
     */
    private function offsetLngLatByBearingMeters(array $lngLat, float $bearingDeg, float $meters): array
    {
        $lng = (float) $lngLat[0];
        $lat = (float) $lngLat[1];

        $R = 6378137.0;
        $brng = deg2rad($bearingDeg);
        $lat1 = deg2rad($lat);
        $lon1 = deg2rad($lng);

        $dDivR = $meters / $R;

        $lat2 = asin(sin($lat1) * cos($dDivR) + cos($lat1) * sin($dDivR) * cos($brng));
        $lon2 = $lon1 + atan2(sin($brng) * sin($dDivR) * cos($lat1), cos($dDivR) - sin($lat1) * sin($lat2));

        $newLat = rad2deg($lat2);
        $newLon = rad2deg($lon2);

        return [$newLon, $newLat];
    }

    /**
     * Douglas–Peucker simplification for GeoJSON (Multi)LineString geometries.
     */
    private function simplifyGeometry(array $geometry, float $toleranceM): array
    {
        if ($toleranceM <= 0) {
            return $geometry;
        }

        $type = $geometry['type'] ?? null;
        if ($type === 'LineString') {
            $coords = $geometry['coordinates'] ?? null;
            if (!is_array($coords) || count($coords) < 3) {
                return $geometry;
            }

            $simp = $this->simplifyLineString($coords, $toleranceM);
            if (count($simp) < 2) {
                return $geometry;
            }

            $geometry['coordinates'] = $simp;
            return $geometry;
        }

        if ($type === 'MultiLineString') {
            $segments = $geometry['coordinates'] ?? null;
            if (!is_array($segments) || empty($segments)) {
                return $geometry;
            }

            $out = [];
            foreach ($segments as $seg) {
                if (!is_array($seg) || count($seg) < 2) {
                    continue;
                }
                $simp = (count($seg) >= 3) ? $this->simplifyLineString($seg, $toleranceM) : $this->normalizeLngLatPoints($seg);
                if (count($simp) >= 2) {
                    $out[] = $simp;
                }
            }

            if (empty($out)) {
                return $geometry;
            }

            $geometry['coordinates'] = $out;
            return $geometry;
        }

        return $geometry;
    }

    /**
     * @param array<int, array{0:float|int,1:float|int}> $points
     * @return array<int, array{0:float,1:float}>
     */
    private function simplifyLineString(array $points, float $toleranceM): array
    {
        $points = $this->normalizeLngLatPoints($points);
        $n = count($points);
        if ($n < 3) {
            return $points;
        }

        $keep = array_fill(0, $n, false);
        $keep[0] = true;
        $keep[$n - 1] = true;

        // Local projection origin for stable meter math.
        $lng0 = $points[0][0];
        $lat0 = $points[0][1];
        $lat0Rad = deg2rad($lat0);
        $R = 6371000.0;

        $xy = [];
        foreach ($points as $pt) {
            $x = deg2rad($pt[0] - $lng0) * $R * cos($lat0Rad);
            $y = deg2rad($pt[1] - $lat0) * $R;
            $xy[] = [$x, $y];
        }

        $stack = [[0, $n - 1]];

        while (!empty($stack)) {
            [$start, $end] = array_pop($stack);
            if ($end <= $start + 1) {
                continue;
            }

            $ax = $xy[$start][0];
            $ay = $xy[$start][1];
            $bx = $xy[$end][0];
            $by = $xy[$end][1];

            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = ($dx * $dx) + ($dy * $dy);

            $maxDist = 0.0;
            $maxIdx = null;

            for ($i = $start + 1; $i < $end; $i++) {
                $px = $xy[$i][0];
                $py = $xy[$i][1];

                if ($len2 <= 0) {
                    $dist = sqrt((($px - $ax) ** 2) + (($py - $ay) ** 2));
                } else {
                    // Perpendicular distance from P to segment AB in meters.
                    $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2;
                    if ($t < 0) {
                        $qx = $ax;
                        $qy = $ay;
                    } elseif ($t > 1) {
                        $qx = $bx;
                        $qy = $by;
                    } else {
                        $qx = $ax + $t * $dx;
                        $qy = $ay + $t * $dy;
                    }
                    $dist = sqrt((($px - $qx) ** 2) + (($py - $qy) ** 2));
                }

                if ($dist > $maxDist) {
                    $maxDist = $dist;
                    $maxIdx = $i;
                }
            }

            if ($maxIdx !== null && $maxDist > $toleranceM) {
                $keep[$maxIdx] = true;
                $stack[] = [$start, $maxIdx];
                $stack[] = [$maxIdx, $end];
            }
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($keep[$i]) {
                $out[] = $points[$i];
            }
        }

        return (count($out) >= 2) ? $out : [$points[0], $points[$n - 1]];
    }

    private function instructionForManeuver(string $type, ?string $modifier, ?float $bearingAfter, ?string $roadName): string
    {
        $road = is_string($roadName) && trim($roadName) !== '' ? trim($roadName) : null;

        return match ($type) {
            'depart' => $bearingAfter === null
                ? ($road ? ('Depart on ' . $road) : 'Depart')
                : ($road ? ('Depart on ' . $road . ' and head ' . $this->bearingToCardinal($bearingAfter)) : ('Depart and head ' . $this->bearingToCardinal($bearingAfter))),
            'arrive' => 'Arrive at your destination',
            'turn' => $modifier === 'uturn'
                ? ($road ? ('Make a U-turn onto ' . $road) : 'Make a U-turn')
                : ($modifier
                    ? ($road ? ('Turn ' . $modifier . ' onto ' . $road) : ('Turn ' . $modifier))
                    : ($road ? ('Turn onto ' . $road) : 'Turn')),
            default => $road ? ('Continue on ' . $road) : 'Continue',
        };
    }

    private function bearingToCardinal(float $bearing): string
    {
        $dirs = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'];
        $idx = (int) round(((($bearing % 360) + 360) % 360) / 45) % 8;
        return $dirs[$idx];
    }

    private function bearingToTurnModifier(float $deltaSigned, float $deltaAbs): string
    {
        $side = $deltaSigned >= 0 ? 'right' : 'left';

        if ($deltaAbs < 45) {
            return 'slight ' . $side;
        }
        if ($deltaAbs < 135) {
            return $side;
        }
        // U-turn vs sharp turn.
        if ($deltaAbs >= 170) {
            return 'uturn';
        }
        return 'sharp ' . $side;
    }

    private function normalizeAngleDeg(float $deg): float
    {
        $deg = fmod($deg + 180.0, 360.0);
        if ($deg < 0) {
            $deg += 360.0;
        }
        return $deg - 180.0;
    }

    private function bearingDeg(array $aLngLat, array $bLngLat): float
    {
        $lat1 = deg2rad((float) $aLngLat[1]);
        $lat2 = deg2rad((float) $bLngLat[1]);
        $dLon = deg2rad((float) $bLngLat[0] - (float) $aLngLat[0]);

        $y = sin($dLon) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
        $brng = rad2deg(atan2($y, $x));
        $brng = fmod($brng + 360.0, 360.0);
        if ($brng < 0) {
            $brng += 360.0;
        }
        return $brng;
    }

    private function polylineDistanceM(array $points): float
    {
        $points = $this->normalizeLngLatPoints($points);
        $sum = 0.0;
        for ($i = 0; $i < count($points) - 1; $i++) {
            $sum += $this->haversineDistanceM($points[$i], $points[$i + 1]);
        }
        return $sum;
    }

    private function haversineDistanceM(array $aLngLat, array $bLngLat): float
    {
        $R = 6371000.0;
        $lat1 = deg2rad((float) $aLngLat[1]);
        $lat2 = deg2rad((float) $bLngLat[1]);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad((float) $bLngLat[0] - (float) $aLngLat[0]);

        $sinDLat = sin($dLat / 2.0);
        $sinDLon = sin($dLon / 2.0);
        $h = ($sinDLat * $sinDLat) + cos($lat1) * cos($lat2) * ($sinDLon * $sinDLon);
        $c = 2.0 * atan2(sqrt($h), sqrt(1.0 - $h));
        return $R * $c;
    }

    private function findIntersectingFloodPolygons(string $routeGeoJson, ?string $vehicleType, string $routingProfile)
    {
        $vehicleType = $vehicleType ?? 'car';

        // Block flooded polygon risk levels based on vehicle capability.
        // - car/motor (light vehicles): can pass risk 1 → block 2–3
        // - truck (heavy): can pass up to risk 2 → block 3
        // - walking: should not pass any risk → block 1–3
        $vehicleTypeNorm = strtolower(trim((string) $vehicleType));
        $profileNorm = strtolower(trim((string) $routingProfile));

        $blockedLevels = match (true) {
            $vehicleTypeNorm === 'walking' || $profileNorm === 'walking' => [1, 2, 3],
            $vehicleTypeNorm === 'truck' => [3],
            default => [2, 3],
        };

        $gids = FloodedGeometry::query()
            ->select('flooded_geojson')
            ->get()
            ->flatMap(function (FloodedGeometry $fg) use ($blockedLevels) {
                $data = json_decode($fg->flooded_geojson, true);
                if (!is_array($data) || empty($data['features'])) {
                    return [];
                }

                return collect($data['features'])
                    ->filter(function ($feature) use ($blockedLevels) {
                        $props = $feature['properties'] ?? [];
                        $risk = isset($props['risk_level']) ? (int) $props['risk_level'] : null;
                        return $risk !== null && in_array($risk, $blockedLevels, true) && isset($props['gid']);
                    })
                    ->pluck('properties.gid');
            })
            ->unique()
            ->values();

        if ($gids->isEmpty()) {
            return collect();
        }

        $rows = Noah::on('gis_data')
            ->whereIn('gid', $gids)
            ->selectRaw('gid, var, ST_AsGeoJSON(geom) as geom')
            ->whereRaw('ST_Intersects(ST_SetSRID(geom, 4326), ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))', [$routeGeoJson])
            ->get();

        return $rows;
    }

    private function computeDetourWaypoint($floodPolygonRow, string $routeGeoJson, string $profile): ?array
    {
        $gid = $floodPolygonRow->gid ?? null;
        if ($gid === null) {
            return null;
        }

            $road = Road::on('gis_data')
                ->selectRaw('gid, name, maxspeed, type, oneway, ST_AsGeoJSON(geom) as geom')
                ->whereRaw('NOT ST_Intersects(ST_SetSRID(geom, 4326), (SELECT ST_SetSRID(geom, 4326) FROM flood_map_exploded WHERE gid = ?))', [$gid])
                ->orderByRaw('ST_Distance(ST_SetSRID(geom, 4326), (SELECT ST_SetSRID(geom, 4326) FROM flood_map_exploded WHERE gid = ?))', [$gid])
                ->first();

        if (!$road) {
            Log::warning('SafeRoutingService: no nearby road found for detour', ['flood_gid' => $gid]);
            return null;
        }

        $roadGeom = json_decode($road->geom, true);
        if (!is_array($roadGeom) || ($roadGeom['type'] ?? null) !== 'LineString') {
            return null;
        }

        $coords = $roadGeom['coordinates'] ?? [];
        if (empty($coords)) {
            return null;
        }

        $index = (int) floor(count($coords) / 2);
        $point = $coords[$index];

        return [
            'lng' => $point[0],
            'lat' => $point[1],
        ];
    }

    /*
     * Example: LineString–Polygon intersection using a PHP geometry library
     * ----------------------------------------------------------------------
     *
     * Although this service relies on PostGIS (ST_Intersects) for production
     * intersection checks, you could perform the same operation purely in PHP
     * with a geometry library such as brick/geo:
     *
     * use Brick\Geo\IO\GeoJSONReader;
     * use Brick\Geo\LineString as BrickLineString;
     * use Brick\Geo\Polygon as BrickPolygon;
     *
     * $reader = new GeoJSONReader();
     *
     * @var BrickLineString $line
     * $line = $reader->read($lineStringGeoJson);
     *
     * @var BrickPolygon $polygon
     * $polygon = $reader->read($polygonGeoJson);
     *
     * if ($line->relate($polygon)->intersects()) {
     *     // The route LineString intersects the flooded Polygon.
     * }
     */
}
