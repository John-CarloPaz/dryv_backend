<?php

namespace App\Console\Commands;

use App\Services\RoadRoutingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DebugRoadRoute extends Command
{
    protected $signature = 'roads:debug-route
        {origin_lat : Origin latitude}
        {origin_lng : Origin longitude}
        {dest_lat : Destination latitude}
        {dest_lng : Destination longitude}
        {--vehicle=car : Vehicle type (car|truck|walking|motor)}
        {--profile=driving : Routing profile (driving|walking)}
        {--avoid-motorway=0 : 1 to avoid motorway edges}
        {--corridor-m= : Optional corridor in meters (performance hint)}';

    protected $description = 'Debug pgRouting safe-route graph connectivity for a given origin/destination.';

    public function handle(RoadRoutingService $roads): int
    {
        $origin = [
            'lat' => (float) $this->argument('origin_lat'),
            'lng' => (float) $this->argument('origin_lng'),
        ];
        $destination = [
            'lat' => (float) $this->argument('dest_lat'),
            'lng' => (float) $this->argument('dest_lng'),
        ];

        $vehicle = (string) $this->option('vehicle');
        $profile = (string) $this->option('profile');
        $avoidMotorway = ((string) $this->option('avoid-motorway')) === '1' || ((string) $this->option('avoid-motorway')) === 'true';

        $corridorM = $this->option('corridor-m');
        $corridorM = is_numeric($corridorM) ? (float) $corridorM : null;

        $this->info('Snapping to vertices...');
        [$startVertex, $endVertex] = $roads->snapVertices($origin, $destination, $vehicle, $profile, $avoidMotorway);

        $this->line('start_vertex=' . ($startVertex ?? 'null') . ' end_vertex=' . ($endVertex ?? 'null'));

        if ($startVertex === null || $endVertex === null) {
            $this->error('Snap failed: no nearby road vertex found.');
            return self::FAILURE;
        }

        // Best-effort connectivity signal: if the vertices are in different connected components,
        // the graph cannot route between them regardless of cost/risk.
        try {
            $this->info('Checking connected components (best effort)...');

            $rows = DB::connection('gis_data')->select(
                "WITH cc AS (\n" .
                "  SELECT * FROM pgr_connectedComponents('SELECT id, source, target, 1::float8 AS cost, 1::float8 AS reverse_cost FROM road_edges_flooded')\n" .
                ")\n" .
                "SELECT node::bigint AS node, component::bigint AS component\n" .
                "FROM cc\n" .
                "WHERE node IN (?::bigint, ?::bigint)",
                [(int) $startVertex, (int) $endVertex]
            );

            $componentMap = [];
            foreach ($rows as $r) {
                if (isset($r->node) && isset($r->component)) {
                    $componentMap[(int) $r->node] = (int) $r->component;
                }
            }

            $cStart = $componentMap[(int) $startVertex] ?? null;
            $cEnd = $componentMap[(int) $endVertex] ?? null;
            if ($cStart !== null && $cEnd !== null) {
                $this->line("component_start={$cStart} component_end={$cEnd}");
                if ($cStart !== $cEnd) {
                    $this->warn('Start/end are in different connected components. Routing cannot succeed until the graph is rebuilt/fixed or data coverage is expanded.');
                }
            } else {
                $this->warn('Connected-components check did not return both vertices.');
            }
        } catch (\Throwable $e) {
            $this->warn('Connected-components check failed: ' . $e->getMessage());
        }

        $this->info('Computing route (this may take a moment)...');
        try {
            $route = $roads->computeSafeRouteByVertices(
                (int) $startVertex,
                (int) $endVertex,
                $vehicle,
                $profile,
                $avoidMotorway,
                $corridorM,
            );

            $geomType = $route['geometry']['type'] ?? null;
            $this->info('OK: route computed.');
            $this->line('geometry_type=' . ($geomType ?? 'null'));
            $this->line('distance_m=' . (string) ($route['distance_m'] ?? 'null'));
            $this->line('duration_s=' . (string) ($route['duration_s'] ?? 'null'));
            $this->line('max_risk_level=' . (string) ($route['max_risk_level'] ?? 'null'));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // The RoadRoutingService now logs diagnostics on "no geometry".
            $this->error('FAILED: ' . $e->getMessage());
            Log::warning('roads:debug-route failed', [
                'error' => $e->getMessage(),
                'origin' => $origin,
                'destination' => $destination,
                'vehicle' => $vehicle,
                'profile' => $profile,
                'avoid_motorway' => $avoidMotorway,
                'corridor_m' => $corridorM,
                'start_vertex' => $startVertex,
                'end_vertex' => $endVertex,
            ]);

            return self::FAILURE;
        }
    }
}
