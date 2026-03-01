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
DO $$
BEGIN
  IF to_regclass('public.road_edges_flooded') IS NOT NULL THEN
    IF EXISTS (
      SELECT 1
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace
      WHERE n.nspname = 'public' AND c.relname = 'road_edges_flooded' AND c.relkind = 'm'
    ) THEN
      EXECUTE 'DROP MATERIALIZED VIEW road_edges_flooded';
    ELSE
      EXECUTE 'DROP VIEW road_edges_flooded';
    END IF;
  END IF;
END $$;
DROP VIEW IF EXISTS road_edges_enriched;
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
DO $$
BEGIN
  IF to_regclass('public.road_edges_flooded') IS NOT NULL THEN
    IF EXISTS (
      SELECT 1
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace
      WHERE n.nspname = 'public' AND c.relname = 'road_edges_flooded' AND c.relkind = 'm'
    ) THEN
      EXECUTE 'DROP MATERIALIZED VIEW road_edges_flooded';
    ELSE
      EXECUTE 'DROP VIEW road_edges_flooded';
    END IF;
  END IF;
END $$;
DROP VIEW IF EXISTS road_edges_enriched;
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
DO $$
BEGIN
  IF to_regclass('public.road_edges_flooded') IS NOT NULL THEN
    IF EXISTS (
      SELECT 1
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace
      WHERE n.nspname = 'public' AND c.relname = 'road_edges_flooded' AND c.relkind = 'm'
    ) THEN
      EXECUTE 'DROP MATERIALIZED VIEW road_edges_flooded';
    ELSE
      EXECUTE 'DROP VIEW road_edges_flooded';
    END IF;
  END IF;
END $$;
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
  CASE
    WHEN r.maxspeed IS NULL THEN NULL
    WHEN (r.maxspeed::text ~ '^[0-9]+$') THEN (r.maxspeed::text)::integer
    ELSE NULL
  END AS road_maxspeed
FROM road_edges e
LEFT JOIN roads r
  ON r.gid::bigint = e.orig_gid;

DO $$
BEGIN
  IF to_regclass('public.current_flood_polygons') IS NULL THEN
    EXECUTE '
      CREATE MATERIALIZED VIEW road_edges_flooded AS
      SELECT
        e.id,
        e.orig_gid,
        e.source,
        e.target,
        e.geom,
        e.length_m,
        e.road_type,
        e.road_maxspeed,
        NULL::integer AS edge_max_risk
      FROM road_edges_enriched e
    ';
  ELSE
    EXECUTE '
      CREATE MATERIALIZED VIEW road_edges_flooded AS
      SELECT
        e.id,
        e.orig_gid,
        e.source,
        e.target,
        e.geom,
        e.length_m,
        e.road_type,
        e.road_maxspeed,
        MAX(fp.risk_level) AS edge_max_risk
      FROM road_edges_enriched e
      LEFT JOIN current_flood_polygons fp
        ON fp.geom && e.geom
       AND ST_Intersects(e.geom, fp.geom)
      GROUP BY
        e.id,
        e.orig_gid,
        e.source,
        e.target,
        e.geom,
        e.length_m,
        e.road_type,
        e.road_maxspeed
    ';
  END IF;
END $$;

-- Indexes to keep routing queries fast.
CREATE UNIQUE INDEX IF NOT EXISTS road_edges_flooded_id_uq ON road_edges_flooded (id);
CREATE INDEX IF NOT EXISTS road_edges_flooded_source_idx ON road_edges_flooded (source);
CREATE INDEX IF NOT EXISTS road_edges_flooded_target_idx ON road_edges_flooded (target);
CREATE INDEX IF NOT EXISTS road_edges_flooded_risk_idx ON road_edges_flooded (edge_max_risk);
CREATE INDEX IF NOT EXISTS road_edges_flooded_type_idx ON road_edges_flooded (road_type);
CREATE INDEX IF NOT EXISTS road_edges_flooded_geom_gist ON road_edges_flooded USING GIST (geom);
ANALYZE road_edges_flooded;
SQL);

                $this->info('Topology + road_edges view finished.');
            }

                if (!$skipSnapFunction) {
                $this->warn('Updating snap_point_to_vertex() to use roads_noded_vertices_pgr...');

                DB::connection($connection)->unprepared(<<<'SQL'
        DROP FUNCTION IF EXISTS snap_point_to_vertex(double precision, double precision, text, text);
              DROP FUNCTION IF EXISTS snap_point_to_vertex(double precision, double precision, text, text, boolean);

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
                AND (
                  CASE
                    WHEN lower(coalesce(in_vehicle_type, '')) = 'walking'
                         OR lower(coalesce(in_routing_profile, '')) = 'walking'
                    THEN COALESCE(e.road_type, '') IN (
                      'bridleway','corridor','cycleway','footway','path','pedestrian','steps',
                      'track','service','residential','unclassified',
                      'tertiary','tertiary_link','secondary','secondary_link','primary','primary_link',
                      'rest_area','services'
                    )
                    ELSE COALESCE(e.road_type, '') IN (
                      'motorway','motorway_link','trunk','trunk_link','primary','primary_link',
                      'secondary','secondary_link','tertiary','tertiary_link',
                      'residential','unclassified','service','track',
                      'rest_area','services','corridor'
                    )
                  END
                )
                AND (
                  in_avoid_motorway IS NOT TRUE
                  OR COALESCE(e.road_type, '') NOT IN ('motorway', 'motorway_link')
                )
            )
          ),
          candidates AS (
            SELECT * FROM good
            UNION ALL
            SELECT * FROM nearest
            WHERE NOT EXISTS (SELECT 1 FROM good)
          )
        SELECT id
        FROM candidates
        ORDER BY the_geom <-> (SELECT geom FROM p)
        LIMIT 1;
        $$;

        CREATE FUNCTION snap_point_to_vertex(
          in_lat  double precision,
          in_lon  double precision,
          in_vehicle_type    text,
          in_routing_profile text
        )
        RETURNS bigint
        LANGUAGE sql
        AS $$
        -- Delegate to the overload so we consistently respect vehicle/profile rules.
        SELECT snap_point_to_vertex(in_lat, in_lon, in_vehicle_type, in_routing_profile, false);
        $$;
        SQL);

                $this->info('snap_point_to_vertex updated.');
              }

              if (!$skipRoutingFunctions) {
                $this->warn('Updating compute_safe_route_geom() overloads (motorway + community report avoidance support)...');

                DB::connection($connection)->unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS compute_safe_route_geom(text, text, bigint, bigint);
DROP FUNCTION IF EXISTS compute_safe_route_geom(text, text, bigint, bigint, boolean);
DROP FUNCTION IF EXISTS compute_safe_route_geom(text, text, bigint, bigint, boolean, double precision);
DROP FUNCTION IF EXISTS compute_safe_route_geom(text, text, bigint, bigint, boolean, double precision, boolean, jsonb, double precision);

-- Overload that supports motorway avoidance and an optional search corridor.
-- Corridor is a performance optimization: when provided, edges are limited to a
-- buffered envelope around the straight line between start/end vertices.
CREATE FUNCTION compute_safe_route_geom(
  in_vehicle_type     text,
  in_routing_profile  text,
  in_start_vertex     bigint,
  in_end_vertex       bigint,
  in_avoid_motorway   boolean,
  in_corridor_m       double precision,
  in_avoid_community_report boolean DEFAULT false,
  in_blocked_community_segments jsonb DEFAULT NULL,
  in_segment_bin_size_m double precision DEFAULT 100.0
)
RETURNS TABLE (
  path_geom_geojson text,
  total_length_m    double precision,
  max_risk_level    integer,
  total_duration_s  double precision
)
LANGUAGE plpgsql
AS $$
DECLARE
  v_vehicle   text := lower(coalesce(in_vehicle_type, 'car'));
  v_profile   text := lower(coalesce(in_routing_profile, 'driving'));
  v_max_risk  integer;
  v_is_walking boolean;
  v_has_illegal_turn boolean;
  v_turnrestricted_edge_count integer;
  v_edges_sql text;
  v_restrict_sql text;
  v_bbox_wkt text;
  v_start_geom geometry;
  v_end_geom geometry;
BEGIN
  -- Vehicle / profile rules:
  -- Light vehicles (car/motor): can pass risk level 1.
  -- Heavy vehicles (truck): can pass up to risk level 2.
  -- Walking: should not pass any risk.
  IF v_vehicle = 'walking' OR v_profile = 'walking' THEN
    v_max_risk := 0;
  ELSIF v_vehicle = 'truck' THEN
    v_max_risk := 2;
  ELSE
    v_max_risk := 1;
  END IF;

  v_is_walking := (v_vehicle = 'walking' OR v_profile = 'walking');

  v_bbox_wkt := NULL;
  IF in_corridor_m IS NOT NULL AND in_corridor_m > 0 THEN
    SELECT the_geom INTO v_start_geom FROM roads_noded_vertices_pgr WHERE id = in_start_vertex;
    SELECT the_geom INTO v_end_geom   FROM roads_noded_vertices_pgr WHERE id = in_end_vertex;

    IF v_start_geom IS NOT NULL AND v_end_geom IS NOT NULL THEN
      v_bbox_wkt := ST_AsEWKT(
        ST_Transform(
          ST_Envelope(
            ST_Buffer(
              ST_Transform(ST_MakeLine(v_start_geom, v_end_geom), 3857),
              in_corridor_m
            )
          ),
          4326
        )
      );
    END IF;
  END IF;

  -- Cost model:
  -- - base cost uses travel time from maxspeed (or a road-type default)
  -- - apply a bias to prefer main roads for driving
  -- - apply a risk penalty so safer edges are preferred when alternatives exist
  -- NOTE: total_duration_s is computed without bias/penalty; it's an ETA estimate.

  v_edges_sql := format($f$
    WITH
      blocked AS (
        SELECT
          (seg->>'road_gid')::bigint AS road_gid,
          (seg->>'segment_key')::int AS segment_key
        FROM jsonb_array_elements(COALESCE(%6$L::jsonb, '[]'::jsonb)) seg
        WHERE %5$L::boolean IS TRUE
      ),
      blocked_geom AS (
        SELECT
          b.road_gid,
          ST_Buffer(
            ST_LineSubstring(
              ST_LineMerge(ST_SetSRID(r.geom, 4326)),
              LEAST(fr.sf1, fr.sf2),
              GREATEST(fr.sf1, fr.sf2)
            )::geography,
            3.0
          )::geometry AS geom
        FROM blocked b
        JOIN roads r ON r.gid::bigint = b.road_gid
        CROSS JOIN LATERAL (
          SELECT
            GREATEST(0.0, LEAST(1.0, (b.segment_key::double precision * %7$L) / NULLIF(ST_Length(ST_SetSRID(r.geom, 4326)::geography), 0))) AS sf1,
            GREATEST(0.0, LEAST(1.0, ((b.segment_key::double precision + 1.0) * %7$L) / NULLIF(ST_Length(ST_SetSRID(r.geom, 4326)::geography), 0))) AS sf2
        ) fr
      )
    SELECT
      e.id,
      e.source,
      e.target,
      CASE
        WHEN %3$L THEN
          (e.length_m / (5.0 / 3.6))
        ELSE
          (e.length_m / (
            (CASE
              WHEN e.road_maxspeed IS NOT NULL AND e.road_maxspeed > 0 THEN e.road_maxspeed
              WHEN COALESCE(e.road_type, '') IN ('motorway', 'motorway_link') THEN 90
              WHEN COALESCE(e.road_type, '') IN ('trunk', 'trunk_link') THEN 70
              WHEN COALESCE(e.road_type, '') IN ('primary', 'primary_link') THEN 60
              WHEN COALESCE(e.road_type, '') IN ('secondary', 'secondary_link') THEN 50
              WHEN COALESCE(e.road_type, '') IN ('tertiary', 'tertiary_link') THEN 40
              WHEN COALESCE(e.road_type, '') IN ('residential', 'unclassified') THEN 30
              WHEN COALESCE(e.road_type, '') IN ('service', 'track') THEN 20
              ELSE 30
            END)::double precision / 3.6
          ))
        END
      * (CASE
          WHEN %3$L THEN 1.0
          WHEN COALESCE(e.road_type, '') IN ('motorway', 'trunk', 'primary', 'motorway_link', 'trunk_link', 'primary_link') THEN 0.90
          WHEN COALESCE(e.road_type, '') IN ('secondary', 'secondary_link') THEN 0.95
          WHEN COALESCE(e.road_type, '') IN ('tertiary', 'tertiary_link') THEN 0.98
          WHEN COALESCE(e.road_type, '') IN ('residential', 'unclassified') THEN 1.00
          WHEN COALESCE(e.road_type, '') IN ('service') THEN 1.15
          WHEN COALESCE(e.road_type, '') IN ('track') THEN 1.25
          ELSE 1.05
        END)
      * (CASE
          WHEN e.edge_max_risk IS NULL THEN 1.0
          ELSE (1.0 + (e.edge_max_risk::double precision * 1.5))
        END)
      AS cost,
      CASE
        WHEN %3$L THEN
          (e.length_m / (5.0 / 3.6))
        ELSE
          (e.length_m / (
            (CASE
              WHEN e.road_maxspeed IS NOT NULL AND e.road_maxspeed > 0 THEN e.road_maxspeed
              WHEN COALESCE(e.road_type, '') IN ('motorway', 'motorway_link') THEN 90
              WHEN COALESCE(e.road_type, '') IN ('trunk', 'trunk_link') THEN 70
              WHEN COALESCE(e.road_type, '') IN ('primary', 'primary_link') THEN 60
              WHEN COALESCE(e.road_type, '') IN ('secondary', 'secondary_link') THEN 50
              WHEN COALESCE(e.road_type, '') IN ('tertiary', 'tertiary_link') THEN 40
              WHEN COALESCE(e.road_type, '') IN ('residential', 'unclassified') THEN 30
              WHEN COALESCE(e.road_type, '') IN ('service', 'track') THEN 20
              ELSE 30
            END)::double precision / 3.6
          ))
        END
      * (CASE
          WHEN %3$L THEN 1.0
          WHEN COALESCE(e.road_type, '') IN ('motorway', 'trunk', 'primary', 'motorway_link', 'trunk_link', 'primary_link') THEN 0.90
          WHEN COALESCE(e.road_type, '') IN ('secondary', 'secondary_link') THEN 0.95
          WHEN COALESCE(e.road_type, '') IN ('tertiary', 'tertiary_link') THEN 0.98
          WHEN COALESCE(e.road_type, '') IN ('residential', 'unclassified') THEN 1.00
          WHEN COALESCE(e.road_type, '') IN ('service') THEN 1.15
          WHEN COALESCE(e.road_type, '') IN ('track') THEN 1.25
          ELSE 1.05
        END)
      * (CASE
          WHEN e.edge_max_risk IS NULL THEN 1.0
          ELSE (1.0 + (e.edge_max_risk::double precision * 1.5))
        END)
      AS reverse_cost
    FROM road_edges_flooded e
    LEFT JOIN blocked_geom bg
      ON bg.road_gid = e.orig_gid::bigint
     AND ST_Intersects(e.geom, bg.geom)
    WHERE COALESCE(e.edge_max_risk, 0) <= %1$L
      AND (%2$L::boolean IS NOT TRUE OR COALESCE(e.road_type, '') NOT IN ('motorway', 'motorway_link'))
      AND (%5$L::boolean IS NOT TRUE OR bg.road_gid IS NULL)
      AND (%4$L IS NULL OR e.geom && ST_GeomFromEWKT(%4$L))
      AND (
        CASE
          WHEN %3$L THEN
            COALESCE(e.road_type, '') IN (
              'bridleway','corridor','cycleway','footway','path','pedestrian','steps',
              'track','service','residential','unclassified',
              'tertiary','tertiary_link','secondary','secondary_link','primary','primary_link',
              'rest_area','services'
            )
            AND COALESCE(e.road_type, '') NOT IN ('motorway','motorway_link','trunk','trunk_link','raceway','construction','proposed','emergency_bay')
          ELSE
            COALESCE(e.road_type, '') IN (
              'motorway','motorway_link','trunk','trunk_link','primary','primary_link',
              'secondary','secondary_link','tertiary','tertiary_link',
              'residential','unclassified','service','track',
              'rest_area','services','corridor'
            )
            AND COALESCE(e.road_type, '') NOT IN ('footway','pedestrian','steps','path','cycleway','bridleway','proposed','construction')
        END
      )
  $f$, v_max_risk, in_avoid_motorway, v_is_walking, v_bbox_wkt, in_avoid_community_report, COALESCE(in_blocked_community_segments, '[]'::jsonb)::text, in_segment_bin_size_m);

  -- Fast path:
  -- - If we're avoiding motorways OR walking, no motorway-link turn restrictions are needed.
  -- - If motorways are allowed, try a fast pgr_dijkstra first; only fall back to
  --   pgr_turnRestrictedPath if the computed path contains an illegal motorway<->surface turn.

  IF in_avoid_motorway IS TRUE OR v_is_walking THEN
    RETURN QUERY
    WITH
      path AS (
        SELECT seq, edge
        FROM pgr_dijkstra(
          v_edges_sql,
          in_start_vertex,
          in_end_vertex,
          directed := false
        )
      ),
      edges AS (
        SELECT
          p.seq AS seq,
          e.geom,
          e.length_m,
          e.edge_max_risk,
          e.road_type,
          e.road_maxspeed,
          CASE
            WHEN v_vehicle = 'walking' OR v_profile = 'walking' THEN (e.length_m / (5.0 / 3.6))
            ELSE (e.length_m / (
              (CASE
                WHEN e.road_maxspeed IS NOT NULL AND e.road_maxspeed > 0 THEN e.road_maxspeed
                WHEN COALESCE(e.road_type, '') IN ('motorway', 'motorway_link') THEN 90
                WHEN COALESCE(e.road_type, '') IN ('trunk', 'trunk_link') THEN 70
                WHEN COALESCE(e.road_type, '') IN ('primary', 'primary_link') THEN 60
                WHEN COALESCE(e.road_type, '') IN ('secondary', 'secondary_link') THEN 50
                WHEN COALESCE(e.road_type, '') IN ('tertiary', 'tertiary_link') THEN 40
                WHEN COALESCE(e.road_type, '') IN ('residential', 'unclassified') THEN 30
                WHEN COALESCE(e.road_type, '') IN ('service', 'track') THEN 20
                ELSE 30
              END)::double precision / 3.6
            ))
          END AS duration_s
        FROM path p
        JOIN road_edges_flooded e ON e.id = abs(p.edge::integer)
        WHERE p.edge <> -1
        ORDER BY p.seq
      )
    SELECT
      ST_AsGeoJSON(
        ST_LineMerge(
          ST_Collect(edges.geom)
        )
      ) AS path_geom_geojson,
      COALESCE(SUM(edges.length_m), 0) AS total_length_m,
      COALESCE(MAX(edges.edge_max_risk), 0) AS max_risk_level,
      COALESCE(SUM(edges.duration_s), 0) AS total_duration_s
    FROM edges;
    RETURN;
  END IF;

  SELECT EXISTS(
    WITH
      path AS (
        SELECT seq, edge
        FROM pgr_dijkstra(
          v_edges_sql,
          in_start_vertex,
          in_end_vertex,
          directed := false
        )
      ),
      edges AS (
        SELECT
          p.seq AS seq,
          COALESCE(e.road_type, '') AS road_type
        FROM path p
        JOIN road_edges_flooded e ON e.id = abs(p.edge::integer)
        WHERE p.edge <> -1
      )
    SELECT 1
    FROM edges a
    JOIN edges b ON b.seq = a.seq + 1
    WHERE (
      (a.road_type = 'motorway' AND b.road_type NOT IN ('motorway', 'motorway_link'))
      OR
      (b.road_type = 'motorway' AND a.road_type NOT IN ('motorway', 'motorway_link'))
    )
    LIMIT 1
  ) INTO v_has_illegal_turn;

  IF v_has_illegal_turn IS NOT TRUE THEN
    RETURN QUERY
    WITH
      path AS (
        SELECT seq, edge
        FROM pgr_dijkstra(
          v_edges_sql,
          in_start_vertex,
          in_end_vertex,
          directed := false
        )
      ),
      edges AS (
        SELECT
          p.seq AS seq,
          e.geom,
          e.length_m,
          e.edge_max_risk,
          e.road_type,
          e.road_maxspeed,
          (e.length_m / (
            (CASE
              WHEN e.road_maxspeed IS NOT NULL AND e.road_maxspeed > 0 THEN e.road_maxspeed
              WHEN COALESCE(e.road_type, '') IN ('motorway', 'motorway_link') THEN 90
              WHEN COALESCE(e.road_type, '') IN ('trunk', 'trunk_link') THEN 70
              WHEN COALESCE(e.road_type, '') IN ('primary', 'primary_link') THEN 60
              WHEN COALESCE(e.road_type, '') IN ('secondary', 'secondary_link') THEN 50
              WHEN COALESCE(e.road_type, '') IN ('tertiary', 'tertiary_link') THEN 40
              WHEN COALESCE(e.road_type, '') IN ('residential', 'unclassified') THEN 30
              WHEN COALESCE(e.road_type, '') IN ('service', 'track') THEN 20
              ELSE 30
            END)::double precision / 3.6
          )) AS duration_s
        FROM path p
        JOIN road_edges_flooded e ON e.id = abs(p.edge::integer)
        WHERE p.edge <> -1
        ORDER BY p.seq
      )
    SELECT
      ST_AsGeoJSON(
        ST_LineMerge(
          ST_Collect(edges.geom)
        )
      ) AS path_geom_geojson,
      COALESCE(SUM(edges.length_m), 0) AS total_length_m,
      COALESCE(MAX(edges.edge_max_risk), 0) AS max_risk_level,
      COALESCE(SUM(edges.duration_s), 0) AS total_duration_s
    FROM edges;
    RETURN;
  END IF;

  -- Slow/correct path: entering/exiting a motorway must be via a motorway_link.
  v_restrict_sql := format($r$
    WITH
      blocked AS (
        SELECT
          (seg->>'road_gid')::bigint AS road_gid,
          (seg->>'segment_key')::int AS segment_key
        FROM jsonb_array_elements(COALESCE(%4$L::jsonb, '[]'::jsonb)) seg
        WHERE %3$L::boolean IS TRUE
      ),
      blocked_geom AS (
        SELECT
          b.road_gid,
          ST_Buffer(
            ST_LineSubstring(
              ST_LineMerge(ST_SetSRID(r.geom, 4326)),
              LEAST(fr.sf1, fr.sf2),
              GREATEST(fr.sf1, fr.sf2)
            )::geography,
            3.0
          )::geometry AS geom
        FROM blocked b
        JOIN roads r ON r.gid::bigint = b.road_gid
        CROSS JOIN LATERAL (
          SELECT
            GREATEST(0.0, LEAST(1.0, (b.segment_key::double precision * %5$L) / NULLIF(ST_Length(ST_SetSRID(r.geom, 4326)::geography), 0))) AS sf1,
            GREATEST(0.0, LEAST(1.0, ((b.segment_key::double precision + 1.0) * %5$L) / NULLIF(ST_Length(ST_SetSRID(r.geom, 4326)::geography), 0))) AS sf2
        ) fr
      ),
      eligible AS (
      SELECT
        e.id::bigint AS id,
        e.source::bigint AS source,
        e.target::bigint AS target,
        e.geom,
        COALESCE(e.edge_max_risk, 0) AS edge_max_risk,
        COALESCE(e.road_type, '') AS road_type
      FROM road_edges_flooded e
      LEFT JOIN blocked_geom bg
        ON bg.road_gid = e.orig_gid::bigint
       AND ST_Intersects(e.geom, bg.geom)
      WHERE COALESCE(e.edge_max_risk, 0) <= %1$L
        AND (%2$L IS NULL OR e.geom && ST_GeomFromEWKT(%2$L))
        AND (%3$L::boolean IS NOT TRUE OR bg.road_gid IS NULL)
        AND (
          COALESCE(e.road_type, '') IN (
            'motorway','motorway_link','trunk','trunk_link','primary','primary_link',
            'secondary','secondary_link','tertiary','tertiary_link',
            'residential','unclassified','service','track',
            'rest_area','services','corridor'
          )
          AND COALESCE(e.road_type, '') NOT IN ('footway','pedestrian','steps','path','cycleway','bridleway','proposed','construction')
        )
    ),
    inc AS (
      SELECT source::bigint AS v, id::bigint AS edge_id, source::bigint AS source, target::bigint AS target, road_type
      FROM eligible
      UNION ALL
      SELECT target::bigint AS v, id::bigint AS edge_id, source::bigint AS source, target::bigint AS target, road_type
      FROM eligible
    ),
    m AS (
      SELECT v, edge_id, source, target FROM inc WHERE road_type = 'motorway'
    ),
    s AS (
      SELECT v, edge_id, source, target FROM inc WHERE road_type NOT IN ('motorway', 'motorway_link')
    ),
    turns AS (
      SELECT DISTINCT
        (CASE WHEN m.v = m.target THEN m.edge_id ELSE -m.edge_id END) AS e1,
        (CASE WHEN s.v = s.source THEN s.edge_id ELSE -s.edge_id END) AS e2
      FROM m
      JOIN s USING (v)
      UNION ALL
      SELECT DISTINCT
        (CASE WHEN s.v = s.target THEN s.edge_id ELSE -s.edge_id END) AS e1,
        (CASE WHEN m.v = m.source THEN m.edge_id ELSE -m.edge_id END) AS e2
      FROM m
      JOIN s USING (v)
    )
    SELECT
      row_number() OVER ()::bigint AS id,
      -1.0::float8 AS cost,
      ARRAY[turns.e1, turns.e2]::bigint[] AS path
    FROM turns
  $r$, v_max_risk, v_bbox_wkt, in_avoid_community_report, COALESCE(in_blocked_community_segments, '[]'::jsonb)::text, in_segment_bin_size_m);

  -- If the strict turn-restricted route yields no edges, fall back to the plain
  -- Dijkstra result rather than returning NULL geometry.
  SELECT COUNT(*)::int
  INTO v_turnrestricted_edge_count
  FROM pgr_turnRestrictedPath(
    v_edges_sql,
    v_restrict_sql,
    in_start_vertex,
    in_end_vertex,
    2,
    true,
    true,
    true,
    true
  ) p
  WHERE p.edge <> -1;

  IF COALESCE(v_turnrestricted_edge_count, 0) = 0 THEN
    RETURN QUERY
    WITH
      path AS (
        SELECT seq, edge
        FROM pgr_dijkstra(
          v_edges_sql,
          in_start_vertex,
          in_end_vertex,
          directed := false
        )
      ),
      edges AS (
        SELECT
          p.seq AS seq,
          e.geom,
          e.length_m,
          e.edge_max_risk,
          e.road_type,
          e.road_maxspeed,
          (e.length_m / (
            (CASE
              WHEN e.road_maxspeed IS NOT NULL AND e.road_maxspeed > 0 THEN e.road_maxspeed
              WHEN COALESCE(e.road_type, '') IN ('motorway', 'motorway_link') THEN 90
              WHEN COALESCE(e.road_type, '') IN ('trunk', 'trunk_link') THEN 70
              WHEN COALESCE(e.road_type, '') IN ('primary', 'primary_link') THEN 60
              WHEN COALESCE(e.road_type, '') IN ('secondary', 'secondary_link') THEN 50
              WHEN COALESCE(e.road_type, '') IN ('tertiary', 'tertiary_link') THEN 40
              WHEN COALESCE(e.road_type, '') IN ('residential', 'unclassified') THEN 30
              WHEN COALESCE(e.road_type, '') IN ('service', 'track') THEN 20
              ELSE 30
            END)::double precision / 3.6
          )) AS duration_s
        FROM path p
        JOIN road_edges_flooded e ON e.id = abs(p.edge::integer)
        WHERE p.edge <> -1
        ORDER BY p.seq
      )
    SELECT
      ST_AsGeoJSON(
        ST_LineMerge(
          ST_Collect(edges.geom)
        )
      ) AS path_geom_geojson,
      COALESCE(SUM(edges.length_m), 0) AS total_length_m,
      COALESCE(MAX(edges.edge_max_risk), 0) AS max_risk_level,
      COALESCE(SUM(edges.duration_s), 0) AS total_duration_s
    FROM edges;
    RETURN;
  END IF;

  RETURN QUERY
  WITH
    path AS (
      SELECT * FROM pgr_turnRestrictedPath(
        v_edges_sql,
        v_restrict_sql,
        in_start_vertex,
        in_end_vertex,
        2,
        true,
        true,
        true,
        true
      )
    ),
    edges AS (
      SELECT
        p.path_seq AS seq,
        e.geom,
        e.length_m,
        e.edge_max_risk,
        e.road_type,
        e.road_maxspeed,
        (e.length_m / (
          (CASE
            WHEN e.road_maxspeed IS NOT NULL AND e.road_maxspeed > 0 THEN e.road_maxspeed
            WHEN COALESCE(e.road_type, '') IN ('motorway', 'motorway_link') THEN 90
            WHEN COALESCE(e.road_type, '') IN ('trunk', 'trunk_link') THEN 70
            WHEN COALESCE(e.road_type, '') IN ('primary', 'primary_link') THEN 60
            WHEN COALESCE(e.road_type, '') IN ('secondary', 'secondary_link') THEN 50
            WHEN COALESCE(e.road_type, '') IN ('tertiary', 'tertiary_link') THEN 40
            WHEN COALESCE(e.road_type, '') IN ('residential', 'unclassified') THEN 30
            WHEN COALESCE(e.road_type, '') IN ('service', 'track') THEN 20
            ELSE 30
          END)::double precision / 3.6
        )) AS duration_s
      FROM path p
      JOIN road_edges_flooded e ON e.id = abs(p.edge::integer)
      WHERE p.edge <> -1
      ORDER BY p.path_seq
    )
  SELECT
    ST_AsGeoJSON(
      ST_LineMerge(
        ST_Collect(edges.geom)
      )
    ) AS path_geom_geojson,
    COALESCE(SUM(edges.length_m), 0) AS total_length_m,
    COALESCE(MAX(edges.edge_max_risk), 0) AS max_risk_level,
    COALESCE(SUM(edges.duration_s), 0) AS total_duration_s
  FROM edges;
END;
$$;

-- Keep the legacy signatures, but delegate to the overload.
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
  max_risk_level    integer,
  total_duration_s  double precision
)
LANGUAGE sql
AS $$
  SELECT *
  FROM compute_safe_route_geom(in_vehicle_type, in_routing_profile, in_start_vertex, in_end_vertex, in_avoid_motorway, NULL);
$$;

CREATE FUNCTION compute_safe_route_geom(
  in_vehicle_type     text,
  in_routing_profile  text,
  in_start_vertex     bigint,
  in_end_vertex       bigint
)
RETURNS TABLE (
  path_geom_geojson text,
  total_length_m    double precision,
  max_risk_level    integer,
  total_duration_s  double precision
)
LANGUAGE sql
AS $$
  SELECT *
  FROM compute_safe_route_geom(in_vehicle_type, in_routing_profile, in_start_vertex, in_end_vertex, false, NULL);
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
