-- Refactor for gis_data to support avoid_motorway in graph routing.
--
-- Assumptions:
-- - public.roads exists and has: gid (primary key), geom, and OSM classification column `highway`.
-- - roads_noded / roads_noded_vertices_pgr / road_edges / road_edges_flooded are built via `php artisan roads:noded-build`.
--
-- If your roads table uses a different column than `highway` (e.g. fclass/road_type),
-- replace r.highway below accordingly.

BEGIN;

-- 1) Ensure road_edges and road_edges_flooded expose orig_gid.
--    If you use the BuildRoadsNoded command from this repo, it will already do this.

-- 2) Add new function signature: compute_safe_route_geom(..., avoid_motorway boolean)
DROP FUNCTION IF EXISTS compute_safe_route_geom(text, text, bigint, bigint, boolean);

CREATE OR REPLACE FUNCTION compute_safe_route_geom(
  in_vehicle_type     text,
  in_routing_profile  text,
  in_start_vertex     bigint,
  in_end_vertex       bigint,
  in_avoid_motorway   boolean DEFAULT false
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
  v_avoid     boolean := coalesce(in_avoid_motorway, false);
BEGIN
  -- Vehicle / profile rules:
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

  -- Build a SQL statement for pgr_dijkstra.
  -- NOTE: pgr_dijkstra only needs id/source/target/cost/reverse_cost;
  -- we can join/filter however we want.
  v_edges_sql := format($f$
    SELECT
      e.id,
      e.source,
      e.target,
      CASE
        WHEN e.edge_max_risk IS NULL THEN e.length_m
        ELSE e.length_m * (1 + e.edge_max_risk)
      END AS cost,
      CASE
        WHEN e.edge_max_risk IS NULL THEN e.length_m
        ELSE e.length_m * (1 + e.edge_max_risk)
      END AS reverse_cost
    FROM road_edges_flooded e
    LEFT JOIN roads r
      ON r.gid::bigint = e.orig_gid::bigint
    WHERE COALESCE(e.edge_max_risk, 0) <= %L
      AND (
        NOT %L
        OR COALESCE(r.highway, '') NOT IN ('motorway', 'motorway_link')
      )
  $f$, v_max_risk, v_avoid);

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

COMMIT;
