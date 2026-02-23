<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BuildRoadsNoded extends Command
{
    protected $signature = 'roads:noded-build
        {--tile=0.05 : Tile size in degrees (SRID 4326)}
        {--pad=0.01 : Tile overlap padding in degrees}
        {--topology-tolerance=0.00001 : pgr_createTopology tolerance in degrees}
    {--mode=copy : Build mode: copy|node (copy preserves real connectivity; node splits at every geometric intersection)}
        {--skip-noding : Skip roads_noded build; only build topology + views}
        {--skip-topology : Skip pgr_createTopology + views}
    {--skip-snap-function : Skip updating snap_point_to_vertex() to use roads_noded_vertices_pgr}
  {--skip-routing-functions : Skip updating compute_safe_route_geom() overloads}
        {--connection=gis_data : Database connection name}';

    protected $description = 'Build tile-noded road network (roads_noded) and pgRouting topology in gis_data.';

    public function handle(): int
    {
        $connection = (string) $this->option('connection');
        $tile = (float) $this->option('tile');
        $pad = (float) $this->option('pad');
        $tolerance = (float) $this->option('topology-tolerance');
      $mode = strtolower((string) $this->option('mode'));

        $skipNoding = (bool) $this->option('skip-noding');
        $skipTopology = (bool) $this->option('skip-topology');
        $skipSnapFunction = (bool) $this->option('skip-snap-function');
        $skipRoutingFunctions = (bool) $this->option('skip-routing-functions');

        $this->info("Using DB connection: {$connection}");
        $this->info("tile={$tile} pad={$pad} topology_tolerance={$tolerance}");
        $this->info("mode={$mode}");

        if (!in_array($mode, ['copy', 'node'], true)) {
          $this->error("Invalid --mode={$mode}. Use copy|node.");
          return self::FAILURE;
        }

        try {
            if (!$skipNoding) {
                $this->warn('Building roads_noded (this can take a long time)...');

            $hasLevelColumn = DB::connection($connection)->selectOne(
              "SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='roads' AND column_name='level' LIMIT 1"
            ) !== null;

            // Node only within the same grade group.
            // - If `roads.level` exists, use it.
            // - Otherwise, approximate using `bridge` (bridge vs non-bridge).
            // This prevents false connections where roads cross in 2D but are grade-separated.
            $groupExpr = $hasLevelColumn
              ? 'COALESCE(r.level, 0)'
              : 'CASE WHEN COALESCE(r.bridge, 0)::int <> 0 THEN 1 ELSE 0 END';

                if ($mode === 'copy') {
                    // Copy mode: preserve the road dataset's intended topology.
                    // This avoids creating false junctions where roads cross in 2D but are grade-separated.
                    DB::connection($connection)->unprepared(<<<'SQL'
DROP VIEW IF EXISTS road_edges_flooded;
DROP VIEW IF EXISTS road_edges;
DROP TABLE IF EXISTS roads_noded CASCADE;
CREATE TABLE roads_noded (
  gid bigserial PRIMARY KEY,
  orig_gid bigint,
  geom geometry(LineString, 4326)
);
SQL);

                    DB::connection($connection)->unprepared(<<<'SQL'
INSERT INTO roads_noded(orig_gid, geom)
SELECT gid::bigint, geom
FROM roads
WHERE geom IS NOT NULL;
SQL);
                } else {
                    // Node mode (legacy): split at every geometric intersection. This can create
                    // false connectivity at bridges/underpasses; use only when you trust the data.
                    DB::connection($connection)->unprepared(<<<'SQL'
DROP VIEW IF EXISTS road_edges_flooded;
DROP VIEW IF EXISTS road_edges;
DROP TABLE IF EXISTS roads_noded CASCADE;
CREATE TABLE roads_noded (
  gid bigserial PRIMARY KEY,
  orig_gid bigint,
  geom geometry(LineString, 4326)
);
SQL);

                    // Tile-based noding with server-side NOTICE output.
                    $sql = <<<SQL
DO $$
DECLARE
  xmin float8; ymin float8; xmax float8; ymax float8;
  x float8; y float8;
  tile float8 := {$tile};
  pad  float8 := {$pad};
  i int := 0;
  g int;
BEGIN
  SELECT ST_XMin(ext), ST_YMin(ext), ST_XMax(ext), ST_YMax(ext)
  INTO xmin, ymin, xmax, ymax
  FROM (SELECT ST_Extent(geom) AS ext FROM roads) s;

  RAISE NOTICE 'Extent xmin=% ymin=% xmax=% ymax=%', xmin, ymin, xmax, ymax;

  y := ymin;
  WHILE y < ymax LOOP
    x := xmin;
    WHILE x < xmax LOOP
      i := i + 1;
      RAISE NOTICE 'Tile %: x=% y=%', i, x, y;

      FOR g IN
        SELECT DISTINCT {$groupExpr} AS g
        FROM roads r
        WHERE r.geom && ST_MakeEnvelope(x - pad, y - pad, x + tile + pad, y + tile + pad, 4326)
      LOOP
        WITH segs AS (
          SELECT (ST_Dump(ST_Node(ST_UnaryUnion(ST_Collect(r.geom))))).geom AS geom
          FROM roads r
          WHERE r.geom && ST_MakeEnvelope(x - pad, y - pad, x + tile + pad, y + tile + pad, 4326)
            AND ({$groupExpr}) = g
        )
        INSERT INTO roads_noded(orig_gid, geom)
        SELECT
          (
            SELECT r2.gid::bigint
            FROM roads r2
            WHERE r2.geom IS NOT NULL
              AND r2.geom && ST_Expand(s.geom, pad)
            ORDER BY r2.geom <-> s.geom
            LIMIT 1
          ) AS orig_gid,
          s.geom
        FROM segs s
        WHERE s.geom IS NOT NULL;
      END LOOP;

      x := x + tile;
    END LOOP;
    y := y + tile;
  END LOOP;

  RAISE NOTICE 'Done. Total noded segments=%', (SELECT COUNT(*) FROM roads_noded);
END $$;
SQL;

                    DB::connection($connection)->unprepared($sql);
                }

                $this->info('roads_noded build finished.');
            }

            if (!$skipTopology) {
                $this->warn('Building pgRouting topology + road_edges view...');

                DB::connection($connection)->unprepared(<<<SQL
ALTER TABLE roads_noded
  ADD COLUMN IF NOT EXISTS source bigint,
  ADD COLUMN IF NOT EXISTS target bigint,
  ADD COLUMN IF NOT EXISTS orig_gid bigint;

-- Force a fresh topology build.
-- If source/target are already populated, pgRouting may skip rebuilding vertices.
UPDATE roads_noded
SET source = NULL,
    target = NULL;

-- Backfill orig_gid for older installs where roads_noded was geometry-only.
-- Best-effort: nearest `roads.gid` by KNN (and bbox prefilter).
UPDATE roads_noded n
SET orig_gid = (
  SELECT r.gid::bigint
  FROM roads r
  WHERE r.geom IS NOT NULL
    AND r.geom && ST_Expand(n.geom, 0.0005)
  ORDER BY r.geom <-> n.geom
  LIMIT 1
)
WHERE n.orig_gid IS NULL;

DROP TABLE IF EXISTS roads_noded_vertices_pgr CASCADE;
SELECT pgr_createTopology('roads_noded', {$tolerance}, 'geom', 'gid', 'source', 'target');

-- Recreate edge views (drop first so we can safely change column sets/order).
DROP VIEW IF EXISTS road_edges_flooded;
DROP VIEW IF EXISTS road_edges_enriched;
DROP VIEW IF EXISTS road_edges;

-- Keep road_edges column types as integer for compatibility with existing views/functions.
CREATE OR REPLACE VIEW road_edges AS
SELECT
  (gid::integer) AS id,
  orig_gid,
  (source::integer) AS source,
  (target::integer) AS target,
  geom,
  ST_Length(geom::geography) AS length_m
FROM roads_noded;

-- Attach road attributes back onto each noded edge via orig_gid.
-- In copy mode, orig_gid is the original roads.gid; in node mode it's the nearest roads.gid.
CREATE OR REPLACE VIEW road_edges_enriched AS
SELECT
  e.id,
  e.orig_gid,
  e.source,
  e.target,
  e.geom,
  e.length_m,
  r.type     AS road_type,
  r.name     AS road_name,
  r.ref      AS road_ref,
  r.oneway   AS road_oneway,
  r.bridge   AS road_bridge,
  r.maxspeed AS road_maxspeed
FROM road_edges e
LEFT JOIN roads r
  ON r.gid::bigint = e.orig_gid;

DO $$
BEGIN
  IF to_regclass('public.current_flood_polygons') IS NULL THEN
    EXECUTE '
      CREATE OR REPLACE VIEW road_edges_flooded AS
      SELECT
        e.id,
        e.orig_gid,
        e.source,
        e.target,
        e.geom,
        e.length_m,
        e.road_type,
        NULL::integer AS edge_max_risk
      FROM road_edges_enriched e
    ';
  ELSE
    EXECUTE '
      CREATE OR REPLACE VIEW road_edges_flooded AS
      SELECT
        e.id,
        e.orig_gid,
        e.source,
        e.target,
        e.geom,
        e.length_m,
        e.road_type,
        MAX(fp.risk_level) AS edge_max_risk
      FROM road_edges_enriched e
      LEFT JOIN current_flood_polygons fp
        ON ST_Intersects(e.geom, fp.geom)
      GROUP BY
        e.id,
        e.orig_gid,
        e.source,
        e.target,
        e.geom,
        e.length_m,
        e.road_type
    ';
  END IF;
END $$;
SQL);

                $this->info('Topology + road_edges view finished.');
            }

                if (!$skipSnapFunction) {
                $this->warn('Updating snap_point_to_vertex() to use roads_noded_vertices_pgr...');

                DB::connection($connection)->unprepared(<<<'SQL'
        DROP FUNCTION IF EXISTS snap_point_to_vertex(double precision, double precision, text, text);
              DROP FUNCTION IF EXISTS snap_point_to_vertex(double precision, double precision, text, text, boolean);

        CREATE FUNCTION snap_point_to_vertex(
          in_lat  double precision,
          in_lon  double precision,
          in_vehicle_type    text,
          in_routing_profile text
        )
        RETURNS bigint
        LANGUAGE sql
        AS $$
        SELECT id
        FROM roads_noded_vertices_pgr
        ORDER BY the_geom <-> ST_SetSRID(ST_MakePoint(in_lon, in_lat), 4326)
        LIMIT 1;
        $$;

        -- Optional overload: avoid motorway-class edges when snapping (best effort).
        CREATE FUNCTION snap_point_to_vertex(
          in_lat  double precision,
          in_lon  double precision,
          in_vehicle_type    text,
          in_routing_profile text,
          in_avoid_motorway  boolean
        )
        RETURNS bigint
        LANGUAGE sql
        AS $$
        WITH
          p AS (
            SELECT ST_SetSRID(ST_MakePoint(in_lon, in_lat), 4326) AS geom
          ),
          nearest AS (
            SELECT id, the_geom
            FROM roads_noded_vertices_pgr
            ORDER BY the_geom <-> (SELECT geom FROM p)
            LIMIT 500
          ),
          good AS (
            SELECT n.id, n.the_geom
            FROM nearest n
            WHERE EXISTS (
              SELECT 1
              FROM road_edges_enriched e
              WHERE (e.source::bigint = n.id OR e.target::bigint = n.id)
                AND COALESCE(e.road_type, '') NOT IN ('motorway', 'motorway_link')
            )
          ),
          candidates AS (
            SELECT * FROM good
            WHERE in_avoid_motorway IS TRUE
            UNION ALL
            SELECT * FROM nearest
            WHERE in_avoid_motorway IS NOT TRUE
               OR NOT EXISTS (SELECT 1 FROM good)
          )
        SELECT id
        FROM candidates
        ORDER BY the_geom <-> (SELECT geom FROM p)
        LIMIT 1;
        $$;
        SQL);

                $this->info('snap_point_to_vertex updated.');
              }

              if (!$skipRoutingFunctions) {
                $this->warn('Updating compute_safe_route_geom() overloads (avoid_motorway support)...');

                DB::connection($connection)->unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS compute_safe_route_geom(text, text, bigint, bigint, boolean);

-- Overload that supports motorway avoidance.
-- Keeps the original 4-arg signature unchanged (so existing callers continue to work).
CREATE FUNCTION compute_safe_route_geom(
  in_vehicle_type     text,
  in_routing_profile  text,
  in_start_vertex     bigint,
  in_end_vertex       bigint,
  in_avoid_motorway   boolean
)
RETURNS TABLE (
  path_geom_geojson text,
  total_length_m    double precision,
  max_risk_level    integer
)
LANGUAGE plpgsql
AS $$
DECLARE
  v_vehicle   text := lower(coalesce(in_vehicle_type, 'car'));
  v_profile   text := lower(coalesce(in_routing_profile, 'driving'));
  v_max_risk  integer;
  v_edges_sql text;
BEGIN
  -- Vehicle / profile rules:
  -- car: allowed risk 1, avoid 2–3
  -- truck: allowed risk 1–2, avoid 3
  -- motorcycle, walking, cycling: avoid all flooded edges
  IF v_vehicle = 'motorcycle' OR v_vehicle = 'motor'
     OR v_profile IN ('walking', 'cycling') THEN
    v_max_risk := 0;
  ELSIF v_vehicle = 'car' THEN
    v_max_risk := 1;
  ELSIF v_vehicle = 'truck' THEN
    v_max_risk := 2;
  ELSE
    v_max_risk := 1;
  END IF;

  v_edges_sql := format($f$
    SELECT
      id,
      source,
      target,
      CASE
        WHEN edge_max_risk IS NULL THEN length_m
        ELSE length_m * (1 + edge_max_risk)
      END AS cost,
      CASE
        WHEN edge_max_risk IS NULL THEN length_m
        ELSE length_m * (1 + edge_max_risk)
      END AS reverse_cost
    FROM road_edges_flooded
    WHERE COALESCE(edge_max_risk, 0) <= %L
      AND (%L::boolean IS NOT TRUE OR COALESCE(road_type, '') NOT IN ('motorway', 'motorway_link'))
  $f$, v_max_risk, in_avoid_motorway);

  RETURN QUERY
  WITH
    path AS (
      SELECT * FROM pgr_dijkstra(
        v_edges_sql,
        in_start_vertex,
        in_end_vertex,
        directed := false
      )
    ),
    edges AS (
      SELECT
        p.seq,
        e.geom,
        e.length_m,
        e.edge_max_risk
      FROM path p
      JOIN road_edges_flooded e ON e.id = p.edge
      WHERE p.edge <> -1
      ORDER BY p.seq
    )
  SELECT
    ST_AsGeoJSON(
      ST_LineMerge(
        ST_Union(edges.geom ORDER BY edges.seq)
      )
    ) AS path_geom_geojson,
    COALESCE(SUM(edges.length_m), 0) AS total_length_m,
    COALESCE(MAX(edges.edge_max_risk), 0) AS max_risk_level
  FROM edges;
END;
$$;
SQL);

                $this->info('compute_safe_route_geom overload updated.');
              }
        } catch (\Throwable $e) {
            Log::error('roads:noded-build failed', [
                'message' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
