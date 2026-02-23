# Graph-Based Safe Routing Setup

This guide documents **all database SQL steps** and **Laravel/PHP components** involved in the pgRouting-based safe routing engine used by `/api/route/safe`.

It is meant as a reproducible recipe for:

- Setting up the road graph and topology inside the `gis_data` PostgreSQL database.
- Creating helper SQL functions used by the Laravel services.
- Understanding which PHP files are involved on the application side.

---

## 1. Laravel / PHP Components

These files implement the safe-routing API in Laravel:

- Controller: `app/Http/Controllers/SafeRouteController.php`
- Request validation: `app/Http/Requests/SafeRouteRequest.php`
- High-level routing orchestrator: `app/Services/SafeRoutingService.php`
- Graph-based routing service (pgRouting): `app/Services/RoadRoutingService.php`
- Routing engine config: `config/safe_routing.php`
- API route definition: `routes/api.php` (`POST /api/route/safe`)
 - Flood polygon sync to gis_data: `app/Jobs/SyncCurrentFloodPolygonsJob.php`

### 1.1. Engine selection

- Environment flag (in `.env`):

  ```env
  SAFE_ROUTING_ENGINE=graph   # or mapbox
  ```

- Config (see `config/safe_routing.php`):

  - When `engine === 'graph'`, `SafeRoutingService` tries the pgRouting-based path first.
  - On failure (no path or DB error), it returns an error response. To use Mapbox instead, set `SAFE_ROUTING_ENGINE=mapbox`.

### 1.2. Response shape

`SafeRouteController` calls `SafeRoutingService::findSafeRoute()` and returns:

```json
{
  "status": "ok",
  "route": {
    "engine": "graph" | "mapbox",
    "routes": [
      /* graph: one route object with MultiLineString geometry
         mapbox: raw Mapbox route objects (LineString geometry, legs, etc.) */
    ]
  }
}
```

The `engine` field tells the client whether the route came from the graph engine or from Mapbox fallback.

---

## 2. Database: Road Graph & Topology (gis_data)

All graph work happens in the `gis_data` PostgreSQL database, which already contains:

- `roads` – road geometries imported from OpenStreetMap (SRID 4326, `geom` column)
- Project NOAH flood polygons and related tables (used elsewhere for flood risk)
- `current_flood_polygons` – rebuilt from Laravel after each flood computation run

You will run the following SQL in `gis_data` (via `psql`, PgAdmin, DBeaver, etc.).

### 2.1. Enable PostGIS and pgRouting

```sql
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pgrouting;
```

### 2.2. Prepare the roads table for topology

Ensure the `roads` table has `source` and `target` columns used by pgRouting.

```sql
ALTER TABLE roads
  ADD COLUMN IF NOT EXISTS source bigint,
  ADD COLUMN IF NOT EXISTS target bigint;
```

If you are rebuilding topology (recommended when tuning tolerance):

```sql
-- Clear any old topology
UPDATE roads SET source = NULL, target = NULL;
DROP TABLE IF EXISTS roads_vertices_pgr CASCADE;
```

### 2.3. Build topology with pgr_createTopology

Use a tolerance appropriate for SRID 4326. **Do not use large tolerances** (e.g. `0.001` or `0.5` degrees) or you will create fake connections, gaps, and routes that cut through buildings.

If your road data came from an OSM shapefile export, you typically must **node/split roads at intersections** first. The quickest reproducible approach is to build a `roads_noded` table and route on that.

Important: Many roads **geometrically intersect** in 2D but are not physically connected (bridges/underpasses). If you blindly node everything, you will create false junctions.

If you want to reproduce our workflow from Laravel, use the Artisan command:

```bash
php artisan roads:noded-build --connection=gis_data --tile=0.05 --pad=0.01 --topology-tolerance=0.00001 --mode=node
```

Modes:

- `--mode=node` (recommended): nodes/splits at intersections, but only **within the same grade group**.
  - If `roads.level` exists, it uses that.
  - Otherwise it approximates grade separation using `roads.bridge` (bridge vs non-bridge).
  - This prevents false connections where a bridge crosses an underpass.
- `--mode=copy` (advanced): copies `roads` geometries into `roads_noded` without splitting.
  - Use this only if your `roads` already has correct `source/target` connectivity and does not need intersection noding.

#### 2.3.1. Build `roads_noded` (tile-based, with progress notices)

This is CPU-heavy on large datasets. Run it in `gis_data` (pgAdmin/DBeaver/psql). You should see `NOTICE` output for each tile.

The Laravel command handles the recommended “node within same grade group” strategy. If you re-implement this in pure SQL, make sure you do **not** node across grade-separated crossings.

Progress checks (run in a second query tab while it’s running):

```sql
SELECT COUNT(*) AS noded_rows FROM roads_noded;

SELECT pid, now() - query_start AS running_for, state, wait_event_type, wait_event
FROM pg_stat_activity
WHERE state <> 'idle'
ORDER BY query_start;
```

#### 2.3.2. Build topology on `roads_noded`

```sql
ALTER TABLE roads_noded
  ADD COLUMN IF NOT EXISTS source bigint,
  ADD COLUMN IF NOT EXISTS target bigint;

DROP TABLE IF EXISTS roads_noded_vertices_pgr CASCADE;
SELECT pgr_createTopology('roads_noded', 0.00001, 'geom', 'gid', 'source', 'target');
```

Note: `pgr_createTopology('roads_noded', ...)` creates a vertices table named `roads_noded_vertices_pgr`.

### 2.4. Helpful indexes

```sql
CREATE INDEX IF NOT EXISTS roads_geom_gist
  ON roads
  USING GIST (geom);

CREATE INDEX IF NOT EXISTS roads_vertices_pgr_geom_gist
  ON roads_vertices_pgr
  USING GIST (the_geom);

-- If routing from roads_noded, index those instead:
CREATE INDEX IF NOT EXISTS roads_noded_geom_gist
  ON roads_noded
  USING GIST (geom);

CREATE INDEX IF NOT EXISTS roads_noded_vertices_pgr_geom_gist
  ON roads_noded_vertices_pgr
  USING GIST (the_geom);

VACUUM ANALYZE roads;
VACUUM ANALYZE roads_vertices_pgr;

VACUUM ANALYZE roads_noded;
VACUUM ANALYZE roads_noded_vertices_pgr;
```

### 2.5. Edges view for routing

Create a simple view that exposes edges with length in meters.

If you previously had a `road_edges` view with `id integer`, `CREATE OR REPLACE VIEW` will fail when switching to `roads_noded` because `roads_noded.gid` is `bigserial`.

To keep compatibility, cast `gid/source/target` to integer:

```sql
CREATE OR REPLACE VIEW road_edges AS
SELECT
  (gid::integer) AS id,
  orig_gid,
  (source::integer) AS source,
  (target::integer) AS target,
  geom   AS geom,
  ST_Length(geom::geography) AS length_m
FROM roads_noded;

-- Optional but recommended: attach road attributes to each edge via orig_gid.
-- This enables filtering (e.g. avoid motorway) without spatial joins.
CREATE OR REPLACE VIEW road_edges_enriched AS
SELECT
  e.id,
  e.orig_gid,
  e.source,
  e.target,
  e.geom,
  e.length_m,
  r.type AS road_type
FROM road_edges e
LEFT JOIN roads r
  ON r.gid::bigint = e.orig_gid;
```

Note: in `--mode=copy`, `roads_noded` is created with a new sequential `gid` plus `orig_gid` (original `roads.gid`) for traceability.

You can sanity-check it with:

```sql
SELECT COUNT(*) AS edge_count,
       MIN(length_m) AS min_len,
       MAX(length_m) AS max_len
FROM road_edges;
```

---

## 3. Helper SQL Functions

### 3.1. snap_point_to_vertex

This function snaps an input lat/lng to the nearest graph vertex.

After topology on `roads_noded`, pgRouting creates `roads_noded_vertices_pgr`. We snap against that.

Important: if you need to change input parameter names/types, PostgreSQL will not allow `CREATE OR REPLACE FUNCTION` to change the signature — `DROP FUNCTION` first.

```sql
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

-- Overload: best-effort snapping that avoids motorway vertices when requested.
-- Requires `road_edges_enriched(road_type)`.
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
```

Usage example:

```sql
SELECT
  snap_point_to_vertex(15.166754, 120.580551, 'car', 'driving') AS start_vid,
  snap_point_to_vertex(15.154253, 120.592161, 'car', 'driving') AS end_vid;
```

These vertex IDs are what `RoadRoutingService` passes into `compute_safe_route_geom`.

### 3.2. Flood-aware compute_safe_route_geom

This function runs Dijkstra on the road graph and returns the route geometry as GeoJSON plus total length, using **flood-risk-aware costs** from `road_edges_flooded` and enforcing vehicle-specific rules.

```sql
CREATE OR REPLACE FUNCTION compute_safe_route_geom(
  in_vehicle_type     text,
  in_routing_profile  text,
  in_start_vertex     bigint,
  in_end_vertex       bigint
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
  $f$, v_max_risk);

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
```

Optional overload: avoid motorway-class edges.

This requires `road_edges_flooded` to include a `road_type` column (the Laravel command creates this from `road_edges_enriched`).

```sql
DROP FUNCTION IF EXISTS compute_safe_route_geom(text, text, bigint, bigint, boolean);

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
```

Usage example:

```sql
SELECT *
FROM compute_safe_route_geom('car', 'driving', 100, 101);

-- Optional: avoid motorway-class edges
SELECT *
FROM compute_safe_route_geom('car', 'driving', 100, 101, true);
```

This is the function called by `RoadRoutingService` from Laravel.

---

## 4. How Laravel Uses These SQL Functions

### 4.1. RoadRoutingService (graph engine)

File: `app/Services/RoadRoutingService.php`

High-level flow:

1. Normalize `vehicle_type` and `routing_profile` to lowercase.
2. Call `snap_point_to_vertex(lat, lng, vehicle_type, routing_profile)` on the `gis_data` connection to get `start_vertex` and `end_vertex`.
3. Call `compute_safe_route_geom(vehicle_type, routing_profile, start_vertex, end_vertex[, avoid_motorway])`.
4. Decode `path_geom_geojson` into a PHP array and return:

   - `geometry` – decoded GeoJSON geometry (MultiLineString)
   - `distance_m` – total length in meters
   - `max_risk_level` – currently always `0` (placeholder)

If any DB error occurs, it logs and throws a `RuntimeException("Graph-based routing is not available.")` so `SafeRoutingService` can fall back.

### 4.2. SafeRoutingService (engine orchestration)

File: `app/Services/SafeRoutingService.php`

1. Reads `SAFE_ROUTING_ENGINE` via `config('safe_routing.engine')`.
2. If engine is `graph`:
   - Calls `RoadRoutingService::computeSafeRoute()`.
   - On success, returns a structure like:

     ```php
     [
       'engine' => 'graph',
       'routes' => [
         [
           'geometry'       => $route['geometry'],
           'distance'       => $route['distance_m'],
           'weight'         => $route['distance_m'],
           'duration'       => null,
           'max_risk_level' => $route['max_risk_level'],
         ],
       ],
     ]
     ```

   - On failure, logs a warning and continues to the Mapbox-based logic.
3. If engine is `mapbox` *or* graph routing fails:
   - Calls Mapbox Directions using the existing heuristic (with flood intersection checks and optional detours).
   - On success and no flood intersections, annotates the Mapbox response with `engine => 'mapbox'` and returns it.

### 4.3. SafeRouteController

File: `app/Http/Controllers/SafeRouteController.php`

- Validates the JSON body using `SafeRouteRequest`.
- Extracts `origin`, `destination`, `routing_profile`, `vehicle_type`, `exclude`, `max_attempts`.
- Calls `SafeRoutingService::findSafeRoute()` and wraps the result:

  ```php
  return response()->json([
      'status' => 'ok',
      'route'  => $route,
  ]);
  ```

---

## 5. Quick End-to-End Test Recipe

1. In `gis_data`, confirm topology and routing work:

   ```sql
   SELECT
     snap_point_to_vertex(15.166754, 120.580551, 'car', 'driving') AS start_vid,
     snap_point_to_vertex(15.154253, 120.592161, 'car', 'driving') AS end_vid;

   -- Replace with real IDs from above
   SELECT *
   FROM compute_safe_route_geom('car', 'driving', <start_vid>, <end_vid>);
   ```

2. In Laravel `.env`:

   ```env
   SAFE_ROUTING_ENGINE=graph
   ```

3. Clear config cache:

   ```bash
   php artisan config:clear
   ```

4. Call the API (e.g. via Postman):

   ```json
   {
     "origin":      { "lat": 15.166754, "lng": 120.580551 },
     "destination": { "lat": 15.154253, "lng": 120.592161 },
     "routing_profile": "driving",
     "vehicle_type": "car"
   }
   ```

5. You should see a response where:

   - `route.engine === "graph"` if a graph path exists between the snapped vertices.
   - Otherwise, `route.engine === "mapbox"` and the route is provided by Mapbox fallback.

---

This file should give you everything needed to rebuild the graph and understand how the Laravel side interacts with it. For flood-aware costs and vehicle-specific risk rules, you can extend `compute_safe_route_geom` later to join the road edges with your NOAH/FloodedGeometry tables and adjust the `cost` expression accordingly.