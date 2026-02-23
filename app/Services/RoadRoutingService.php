<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RoadRoutingService
{
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

    public function computeSafeRouteByVertices(int $startVertex, int $endVertex, string $vehicleType, string $routingProfile = 'driving', bool $avoidMotorway = false): array
    {
        try {
            if ($avoidMotorway) {
                $row = $this->selectComputeSafeRouteGeomWithAvoidMotorway($vehicleType, $routingProfile, $startVertex, $endVertex);
            } else {
                $row = DB::connection('gis_data')->selectOne(
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
        } catch (RuntimeException $e) {
            // Preserve domain errors (e.g., no route, avoid_motorway unsupported).
            throw $e;
        } catch (\Throwable $e) {
            Log::error('RoadRoutingService: error calling compute_safe_route_geom', [
                'error' => $e->getMessage(),
                'avoid_motorway' => $avoidMotorway,
            ]);
            throw new RuntimeException('Graph-based routing is not available.');
        }

        if (!$row || empty($row->path_geom_geojson)) {
            Log::warning('RoadRoutingService: compute_safe_route_geom returned no geometry', [
                'start_vertex' => $startVertex,
                'end_vertex' => $endVertex,
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
        ]);

        return [
            'geometry'       => $geometry,
            'distance_m'     => isset($row->total_length_m) ? (float) $row->total_length_m : null,
            'max_risk_level' => isset($row->max_risk_level) ? (int) $row->max_risk_level : null,
            'start_vertex'   => $startVertex,
            'end_vertex'     => $endVertex,
        ];
    }

    private function snapToNetwork(float $lat, float $lng, string $vehicleType, string $routingProfile, bool $avoidMotorway = false): ?int
    {
        try {
            if ($avoidMotorway) {
                $row = $this->selectSnapPointToVertexWithAvoidMotorway($lat, $lng, $vehicleType, $routingProfile);
            } else {
                $row = DB::connection('gis_data')->selectOne(
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
            return DB::connection('gis_data')->selectOne(
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
            return DB::connection('gis_data')->selectOne(
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
