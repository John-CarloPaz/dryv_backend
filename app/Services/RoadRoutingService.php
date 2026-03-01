<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RoadRoutingService
{
    private function isWalking(string $vehicleType, string $routingProfile): bool
    {
        $v = strtolower(trim($vehicleType));
        $p = strtolower(trim($routingProfile));

        return $v === 'walking' || $p === 'walking';
    }

    private function computeMaxRiskBudget(string $vehicleType, string $routingProfile): int
    {
        // Keep in sync with the DB function installed by `php artisan roads:noded-build`.
        if ($this->isWalking($vehicleType, $routingProfile)) {
            return 0;
        }

        $v = strtolower(trim($vehicleType));
        if ($v === 'truck') {
            return 2;
        }

        return 1;
    }

    /**
     * Best-effort diagnostics to distinguish:
     * - disconnected graph / missing topology
     * - snapping to an isolated vertex
     * - overly strict filtering (risk/road type/motorway avoidance/corridor)
     */
    private function diagnoseNoGeometry(
        int $startVertex,
        int $endVertex,
        string $vehicleType,
        string $routingProfile,
        bool $avoidMotorway,
        ?float $corridorM,
    ): array {
        $isWalking = $this->isWalking($vehicleType, $routingProfile);
        $maxRisk = $this->computeMaxRiskBudget($vehicleType, $routingProfile);

        $allowedTypes = $isWalking
            ? [
                'bridleway', 'corridor', 'cycleway', 'footway', 'path', 'pedestrian', 'steps',
                'track', 'service', 'residential', 'unclassified',
                'tertiary', 'tertiary_link', 'secondary', 'secondary_link', 'primary', 'primary_link',
                'rest_area', 'services',
            ]
            : [
                'motorway', 'motorway_link', 'trunk', 'trunk_link', 'primary', 'primary_link',
                'secondary', 'secondary_link', 'tertiary', 'tertiary_link',
                'residential', 'unclassified', 'service', 'track',
                'rest_area', 'services', 'corridor',
            ];

        $startPt = null;
        $endPt = null;
        try {
            $startPt = $this->gisSelectOne(
                'SELECT ST_Y(the_geom)::double precision AS lat, ST_X(the_geom)::double precision AS lng
                 FROM roads_noded_vertices_pgr WHERE id = ?::bigint',
                [$startVertex]
            );
            $endPt = $this->gisSelectOne(
                'SELECT ST_Y(the_geom)::double precision AS lat, ST_X(the_geom)::double precision AS lng
                 FROM roads_noded_vertices_pgr WHERE id = ?::bigint',
                [$endVertex]
            );
        } catch (\Throwable $e) {
            // ignore; diagnostics are best-effort
        }

        $degStartAll = null;
        $degEndAll = null;
        try {
            $degStartAllRow = $this->gisSelectOne(
                'SELECT COUNT(*)::int AS c FROM road_edges_flooded WHERE source = ?::bigint OR target = ?::bigint',
                [$startVertex, $startVertex]
            );
            $degEndAllRow = $this->gisSelectOne(
                'SELECT COUNT(*)::int AS c FROM road_edges_flooded WHERE source = ?::bigint OR target = ?::bigint',
                [$endVertex, $endVertex]
            );
            $degStartAll = $degStartAllRow ? (int) $degStartAllRow->c : null;
            $degEndAll = $degEndAllRow ? (int) $degEndAllRow->c : null;
        } catch (\Throwable $e) {
            // ignore
        }

        $degStartEligible = null;
        $degEndEligible = null;
        try {
            $typePlaceholders = implode(',', array_fill(0, count($allowedTypes), '?'));

            $motorwayClause = $avoidMotorway
                ? " AND COALESCE(e.road_type, '') NOT IN ('motorway','motorway_link') "
                : '';

            $corridorClause = '';
            $corridorBindings = [];
            if ($corridorM !== null && is_finite($corridorM) && $corridorM > 0) {
                // Mirror compute_safe_route_geom() corridor bbox logic (approx): edges must intersect
                // an envelope around the start->end line buffered by corridor meters.
                $corridorClause =
                    ' AND e.geom && (
                        SELECT ST_Transform(
                            ST_Envelope(
                                ST_Buffer(
                                    ST_Transform(ST_MakeLine(s.the_geom, t.the_geom), 3857),
                                    ?::double precision
                                )
                            ),
                            4326
                        )
                        FROM roads_noded_vertices_pgr s
                        JOIN roads_noded_vertices_pgr t ON true
                        WHERE s.id = ?::bigint AND t.id = ?::bigint
                    ) ';
                $corridorBindings = [(float) $corridorM, $startVertex, $endVertex];
            }

            $eligibleSqlBase =
                'SELECT COUNT(*)::int AS c
                 FROM road_edges_flooded e
                 WHERE (e.source = ?::bigint OR e.target = ?::bigint)
                   AND COALESCE(e.edge_max_risk, 0) <= ?::int' .
                $motorwayClause .
                $corridorClause .
                ' AND COALESCE(e.road_type, \'\') IN (' . $typePlaceholders . ')';

            $degStartEligibleRow = $this->gisSelectOne(
                $eligibleSqlBase,
                array_merge([$startVertex, $startVertex, $maxRisk], $corridorBindings, $allowedTypes)
            );
            $degEndEligibleRow = $this->gisSelectOne(
                $eligibleSqlBase,
                array_merge([$endVertex, $endVertex, $maxRisk], $corridorBindings, $allowedTypes)
            );

            $degStartEligible = $degStartEligibleRow ? (int) $degStartEligibleRow->c : null;
            $degEndEligible = $degEndEligibleRow ? (int) $degEndEligibleRow->c : null;
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'vehicle_type' => strtolower(trim($vehicleType)),
            'routing_profile' => strtolower(trim($routingProfile)),
            'avoid_motorway' => $avoidMotorway,
            'corridor_m' => $corridorM,
            'max_risk_budget' => $maxRisk,
            'is_walking' => $isWalking,
            'start_vertex_exists' => $startPt !== null,
            'end_vertex_exists' => $endPt !== null,
            'start_vertex_latlng' => $startPt ? [(float) $startPt->lat, (float) $startPt->lng] : null,
            'end_vertex_latlng' => $endPt ? [(float) $endPt->lat, (float) $endPt->lng] : null,
            'start_degree_all' => $degStartAll,
            'end_degree_all' => $degEndAll,
            'start_degree_eligible' => $degStartEligible,
            'end_degree_eligible' => $degEndEligible,
        ];
    }

    private function gisSelectOne(string $sql, array $bindings = []): ?object
    {
        $gis = DB::connection('gis_data');
        $timeoutMs = (int) config('safe_routing.graph_db_statement_timeout_ms', 0);

        if ($timeoutMs > 0) {
            return $gis->transaction(function () use ($gis, $sql, $bindings, $timeoutMs) {
                $gis->statement('SET LOCAL statement_timeout = ' . (int) $timeoutMs);
                return $gis->selectOne($sql, $bindings);
            });
        }

        return $gis->selectOne($sql, $bindings);
    }

    /**
     * Compute the safest route over the road network using pgRouting / PostGIS.
     *
     * Expects the following SQL helpers to exist in the `gis_data` database:
     * - snap_point_to_vertex(lat, lon, vehicle_type, routing_profile[, avoid_motorway]) -> BIGINT vertex id
     * - compute_safe_route_geom(vehicle_type, routing_profile, start_vertex, end_vertex[, avoid_motorway])
     *     returning one row with columns: path_geom_geojson, total_length_m, max_risk_level
     */
    public function computeSafeRoute(array $origin, array $destination, string $vehicleType, string $routingProfile = 'driving', bool $avoidMotorway = false): array
    {
        $vehicleType = strtolower($vehicleType ?: 'car');
        $routingProfile = strtolower($routingProfile ?: 'driving');

        Log::info('RoadRoutingService: computeSafeRoute start', [
            'origin' => $origin,
            'destination' => $destination,
            'vehicle_type' => $vehicleType,
            'routing_profile' => $routingProfile,
            'avoid_motorway' => $avoidMotorway,
        ]);

        [$startVertex, $endVertex] = $this->snapVertices($origin, $destination, $vehicleType, $routingProfile, $avoidMotorway);

        Log::info('RoadRoutingService: snapped to graph vertices', [
            'start_vertex' => $startVertex,
            'end_vertex' => $endVertex,
        ]);

        if ($startVertex !== null && $endVertex !== null && $startVertex === $endVertex) {
            Log::warning('RoadRoutingService: start and end snapped to same vertex (topology tolerance may be too large)', [
                'vertex_id' => $startVertex,
            ]);
        }

        if ($startVertex === null || $endVertex === null) {
            throw new RuntimeException('No road network found near origin or destination.');
        }

        return $this->computeSafeRouteByVertices($startVertex, $endVertex, $vehicleType, $routingProfile, $avoidMotorway);
    }

    /**
     * Snap origin/destination to graph vertex IDs.
     * @return array{0:?int,1:?int}
     */
    public function snapVertices(array $origin, array $destination, string $vehicleType, string $routingProfile = 'driving', bool $avoidMotorway = false): array
    {
        $startVertex = $this->snapToNetwork((float) $origin['lat'], (float) $origin['lng'], $vehicleType, $routingProfile, $avoidMotorway);
        $endVertex   = $this->snapToNetwork((float) $destination['lat'], (float) $destination['lng'], $vehicleType, $routingProfile, $avoidMotorway);

        return [$startVertex, $endVertex];
    }

    public function computeSafeRouteByVertices(
        int $startVertex,
        int $endVertex,
        string $vehicleType,
        string $routingProfile = 'driving',
        bool $avoidMotorway = false,
        ?float $corridorM = null,
        bool $avoidCommunityReport = false,
        ?array $blockedCommunitySegments = null,
        ?float $communitySegmentBinSizeM = null,
    ): array
    {
        $row = null;

        try {
            $corridorM = ($corridorM !== null && is_finite($corridorM) && $corridorM > 0) ? $corridorM : null;

            $avoidCommunityReport = (bool) $avoidCommunityReport;
            if (!is_array($blockedCommunitySegments) || count($blockedCommunitySegments) === 0) {
                $avoidCommunityReport = false;
            }

            $blockedJson = '[]';
            $binSizeM = ($communitySegmentBinSizeM !== null && is_finite($communitySegmentBinSizeM) && $communitySegmentBinSizeM > 0)
                ? (float) $communitySegmentBinSizeM
                : 100.0;

            if ($avoidCommunityReport) {
                $enc = json_encode(array_values($blockedCommunitySegments), JSON_UNESCAPED_SLASHES);
                $blockedJson = ($enc !== false && is_string($enc) && $enc !== '') ? $enc : '[]';

                // Prefer the new DB signature that supports community segment avoidance.
                $row = $this->selectComputeSafeRouteGeomWithCommunity(
                    $vehicleType,
                    $routingProfile,
                    $startVertex,
                    $endVertex,
                    $avoidMotorway,
                    $corridorM,
                    $blockedJson,
                    $binSizeM,
                );

                if ($row === null) {
                    // DB function not installed/updated; fall back to legacy routing (without community avoidance).
                    $avoidCommunityReport = false;
                }
            }

            if ($row === null && $corridorM !== null) {
                $row = $this->selectComputeSafeRouteGeomWithCorridor($vehicleType, $routingProfile, $startVertex, $endVertex, $avoidMotorway, $corridorM);
                if ($row === null) {
                    // DB function not installed (older DB). Fall back to legacy behavior.
                    $corridorM = null;
                }
            }

            if ($row === null && $corridorM === null) {
                if ($avoidMotorway) {
                    $row = $this->selectComputeSafeRouteGeomWithAvoidMotorway($vehicleType, $routingProfile, $startVertex, $endVertex);
                } else {
                    // Prefer the extended output (duration) when available; fall back for older DB installs.
                    try {
                        $row = $this->gisSelectOne(
                            'SELECT path_geom_geojson, total_length_m, max_risk_level, total_duration_s
                             FROM compute_safe_route_geom(?, ?, ?, ?)',
                            [
                                $vehicleType,
                                $routingProfile,
                                $startVertex,
                                $endVertex,
                            ]
                        );
                    } catch (\Throwable $e) {
                        $row = $this->gisSelectOne(
                            'SELECT path_geom_geojson, total_length_m, max_risk_level
                             FROM compute_safe_route_geom(?, ?, ?, ?)',
                            [
                                $vehicleType,
                                $routingProfile,
                                $startVertex,
                                $endVertex,
                            ]
                        );
                    }
                }
            }
        } catch (RuntimeException $e) {
            // Preserve domain errors (e.g., no route, avoid_motorway unsupported).
            throw $e;
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'statement timeout') || str_contains($msg, 'canceling statement')) {
                throw new RuntimeException('Routing attempt timed out.');
            }

            Log::error('RoadRoutingService: error calling compute_safe_route_geom', [
                'error' => $e->getMessage(),
                'avoid_motorway' => $avoidMotorway,
            ]);
            throw new RuntimeException('Graph-based routing is not available.');
        }

        if (!$row || empty($row->path_geom_geojson)) {
            $diag = $this->diagnoseNoGeometry($startVertex, $endVertex, $vehicleType, $routingProfile, $avoidMotorway, $corridorM);
            Log::warning('RoadRoutingService: compute_safe_route_geom returned no geometry', [
                'start_vertex' => $startVertex,
                'end_vertex' => $endVertex,
                'diagnostics' => $diag,
            ]);
            throw new RuntimeException('No safe path can be recommended.');
        }

        $geometry = json_decode($row->path_geom_geojson, true);
        if (!is_array($geometry)) {
            throw new RuntimeException('Invalid route geometry returned from compute_safe_route_geom.');
        }

        Log::info('RoadRoutingService: graph route computed', [
            'geometry_type' => $geometry['type'] ?? null,
            'distance_m' => isset($row->total_length_m) ? (float) $row->total_length_m : null,
            'max_risk_level' => isset($row->max_risk_level) ? (int) $row->max_risk_level : null,
            'duration_s' => isset($row->total_duration_s) ? (float) $row->total_duration_s : null,
        ]);

        return [
            'geometry'       => $geometry,
            'distance_m'     => isset($row->total_length_m) ? (float) $row->total_length_m : null,
            'max_risk_level' => isset($row->max_risk_level) ? (int) $row->max_risk_level : null,
            'duration_s'     => isset($row->total_duration_s) ? (float) $row->total_duration_s : null,
            'start_vertex'   => $startVertex,
            'end_vertex'     => $endVertex,
        ];
    }

    private function selectComputeSafeRouteGeomWithCommunity(
        string $vehicleType,
        string $routingProfile,
        int $startVertex,
        int $endVertex,
        bool $avoidMotorway,
        ?float $corridorM,
        string $blockedSegmentsJson,
        float $segmentBinSizeM,
    ): ?object {
        try {
            // Prefer extended output (duration) when available; fall back for older installs.
            try {
                return $this->gisSelectOne(
                    'SELECT path_geom_geojson, total_length_m, max_risk_level, total_duration_s
                     FROM compute_safe_route_geom(?::text, ?::text, ?::bigint, ?::bigint, ?::boolean, ?::double precision, ?::boolean, ?::jsonb, ?::double precision)',
                    [
                        $vehicleType,
                        $routingProfile,
                        $startVertex,
                        $endVertex,
                        $avoidMotorway,
                        $corridorM,
                        true,
                        $blockedSegmentsJson,
                        $segmentBinSizeM,
                    ]
                );
            } catch (\Throwable $e) {
                return $this->gisSelectOne(
                    'SELECT path_geom_geojson, total_length_m, max_risk_level
                     FROM compute_safe_route_geom(?::text, ?::text, ?::bigint, ?::bigint, ?::boolean, ?::double precision, ?::boolean, ?::jsonb, ?::double precision)',
                    [
                        $vehicleType,
                        $routingProfile,
                        $startVertex,
                        $endVertex,
                        $avoidMotorway,
                        $corridorM,
                        true,
                        $blockedSegmentsJson,
                        $segmentBinSizeM,
                    ]
                );
            }
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'compute_safe_route_geom') && (str_contains($msg, 'does not exist') || str_contains($msg, 'undefined function'))) {
                Log::warning('RoadRoutingService: compute_safe_route_geom does not support community avoidance; ignoring toggle', [
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
            throw $e;
        }
    }

    private function selectComputeSafeRouteGeomWithCorridor(
        string $vehicleType,
        string $routingProfile,
        int $startVertex,
        int $endVertex,
        bool $avoidMotorway,
        float $corridorM,
    ): ?object {
        try {
            // Prefer extended output (duration) when available; fall back for older DB installs.
            try {
                return $this->gisSelectOne(
                    'SELECT path_geom_geojson, total_length_m, max_risk_level, total_duration_s
                     FROM compute_safe_route_geom(?::text, ?::text, ?::bigint, ?::bigint, ?::boolean, ?::double precision)',
                    [
                        $vehicleType,
                        $routingProfile,
                        $startVertex,
                        $endVertex,
                        $avoidMotorway,
                        $corridorM,
                    ]
                );
            } catch (\Throwable $e) {
                return $this->gisSelectOne(
                    'SELECT path_geom_geojson, total_length_m, max_risk_level
                     FROM compute_safe_route_geom(?::text, ?::text, ?::bigint, ?::bigint, ?::boolean, ?::double precision)',
                    [
                        $vehicleType,
                        $routingProfile,
                        $startVertex,
                        $endVertex,
                        $avoidMotorway,
                        $corridorM,
                    ]
                );
            }
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'compute_safe_route_geom') && (str_contains($msg, 'does not exist') || str_contains($msg, 'undefined function'))) {
                // Older DB schema; caller can fall back to legacy signatures.
                return null;
            }

            throw $e;
        }
    }

    private function snapToNetwork(float $lat, float $lng, string $vehicleType, string $routingProfile, bool $avoidMotorway = false): ?int
    {
        try {
            if ($avoidMotorway) {
                $row = $this->selectSnapPointToVertexWithAvoidMotorway($lat, $lng, $vehicleType, $routingProfile);
            } else {
                $row = $this->gisSelectOne(
                    'SELECT snap_point_to_vertex(?, ?, ?, ?) AS vertex_id',
                    [
                        $lat,
                        $lng,
                        $vehicleType,
                        $routingProfile,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::error('RoadRoutingService: error calling snap_point_to_vertex', [
                'error' => $e->getMessage(),
                'avoid_motorway' => $avoidMotorway,
            ]);
            return null;
        }

        if (!$row || $row->vertex_id === null) {
            return null;
        }

        return (int) $row->vertex_id;
    }

    private function selectComputeSafeRouteGeomWithAvoidMotorway(string $vehicleType, string $routingProfile, int $startVertex, int $endVertex): ?object
    {
        try {
            // Prefer extended output (duration) when available; fall back for older DB installs.
            try {
                return $this->gisSelectOne(
                    'SELECT path_geom_geojson, total_length_m, max_risk_level, total_duration_s
                     FROM compute_safe_route_geom(?::text, ?::text, ?::bigint, ?::bigint, ?::boolean)',
                    [
                        $vehicleType,
                        $routingProfile,
                        $startVertex,
                        $endVertex,
                        true,
                    ]
                );
            } catch (\Throwable $e) {
                return $this->gisSelectOne(
                    'SELECT path_geom_geojson, total_length_m, max_risk_level
                     FROM compute_safe_route_geom(?::text, ?::text, ?::bigint, ?::bigint, ?::boolean)',
                    [
                        $vehicleType,
                        $routingProfile,
                        $startVertex,
                        $endVertex,
                        true,
                    ]
                );
            }
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'compute_safe_route_geom') && (str_contains($msg, 'does not exist') || str_contains($msg, 'undefined function'))) {
                Log::warning('RoadRoutingService: compute_safe_route_geom does not support avoid_motorway', [
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException('Motorway avoidance is not supported by the current graph routing database functions.');
            }

            throw $e;
        }
    }

    private function selectSnapPointToVertexWithAvoidMotorway(float $lat, float $lng, string $vehicleType, string $routingProfile): ?object
    {
        try {
            return $this->gisSelectOne(
                'SELECT snap_point_to_vertex(?::double precision, ?::double precision, ?::text, ?::text, ?::boolean) AS vertex_id',
                [
                    $lat,
                    $lng,
                    $vehicleType,
                    $routingProfile,
                    true,
                ]
            );
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'snap_point_to_vertex') && (str_contains($msg, 'does not exist') || str_contains($msg, 'undefined function'))) {
                Log::warning('RoadRoutingService: snap_point_to_vertex does not support avoid_motorway; falling back to legacy signature', [
                    'error' => $e->getMessage(),
                ]);

                return DB::connection('gis_data')->selectOne(
                    'SELECT snap_point_to_vertex(?, ?, ?, ?) AS vertex_id',
                    [
                        $lat,
                        $lng,
                        $vehicleType,
                        $routingProfile,
                    ]
                );
            }

            throw $e;
        }
    }
}
