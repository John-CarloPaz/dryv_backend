# Flood–Graph Integration (PHP + SQL)

This document explains the **PHP-side configuration** that connects:

- Flood data (`FloodedGeometry` JSON in the Laravel DB),
- The `current_flood_polygons` table in the `gis_data` database,
- And the pgRouting road graph (`roads` / `road_edges`),

so that `/api/route/safe` can compute routes that respect flood risk and vehicle rules.

---

## 1. Key PHP Components

These classes participate in the end-to-end flow:

- Flood geometry aggregation:
  - [app/Jobs/ComputeFloodedPolygonJob.php](app/Jobs/ComputeFloodedPolygonJob.php)
  - [app/Models/FloodedGeometry.php](app/Models/FloodedGeometry.php)
  - [app/Models/Noah.php](app/Models/Noah.php) (table: `flood_map_exploded` on `gis_data`)
- Flood sync into `gis_data`:
  - [app/Jobs/SyncCurrentFloodPolygonsJob.php](app/Jobs/SyncCurrentFloodPolygonsJob.php)
- Mapbox tileset upload (for visualization only):
  - [app/Jobs/UploadGeoJsonToMapbox.php](app/Jobs/UploadGeoJsonToMapbox.php)
- Graph-based routing:
  - [app/Services/RoadRoutingService.php](app/Services/RoadRoutingService.php)
  - [app/Services/SafeRoutingService.php](app/Services/SafeRoutingService.php)
  - [app/Http/Controllers/SafeRouteController.php](app/Http/Controllers/SafeRouteController.php)
  - [app/Http/Requests/SafeRouteRequest.php](app/Http/Requests/SafeRouteRequest.php)
- Configuration:
  - [config/database.php](config/database.php) – defines the `gis_data` connection.
  - [config/safe_routing.php](config/safe_routing.php) – selects `graph` vs `mapbox` engine.

---

## 2. Flood Pipeline → current_flood_polygons (gis_data)

### 2.1. ComputeFloodedPolygonJob

File: [app/Jobs/ComputeFloodedPolygonJob.php](app/Jobs/ComputeFloodedPolygonJob.php)

Per barangay, this job:

1. Queries the NOAH table (`flood_map_exploded` on `gis_data`) for each flooded `gid` and builds a GeoJSON FeatureCollection with:
   - `geometry` from NOAH,
   - `properties.gid`,
   - `properties.rwr` (score),
   - `properties.risk_level`.
2. Stores that FeatureCollection JSON into `flooded_geometries.flooded_geojson`.
3. Uses a cache-based counter (`compute_flooded_expected` / `compute_flooded_completed`) to detect when **all** polygon jobs are done.
4. When all are complete, it dispatches two follow-up jobs:
   - `SyncCurrentFloodPolygonsJob` (to sync to `gis_data`), then
   - `UploadGeoJsonToMapbox` (to update tilesets).

### 2.2. SyncCurrentFloodPolygonsJob

File: [app/Jobs/SyncCurrentFloodPolygonsJob.php](app/Jobs/SyncCurrentFloodPolygonsJob.php)

This job is responsible for keeping the `gis_data.current_flood_polygons` table in sync with the latest `FloodedGeometry` data.

High-level behavior:

1. Iterates all `FloodedGeometry` rows via `cursor()` to avoid memory spikes.
2. For each stored `flooded_geojson` FeatureCollection, it:
   - Decodes the JSON,
   - Extracts `gid` and `risk_level` from each feature,
   - Builds an in-memory map: `gid => max(risk_level)` across all barangays.
3. On the `gis_data` connection, it ensures the table exists:

   ```sql
   CREATE TABLE IF NOT EXISTS current_flood_polygons (
       gid        integer PRIMARY KEY,
       risk_level integer NOT NULL,
       geom       geometry(Polygon, 4326) NOT NULL
   );
   ```

4. Inside a single transaction, it:
   - `TRUNCATE TABLE current_flood_polygons;`
   - For each `gid` in the map, executes an `INSERT` that pulls `geom` from NOAH:

     ```sql
     INSERT INTO current_flood_polygons (gid, risk_level, geom)
     SELECT gid, :risk_level, ST_SetSRID(geom, 4326)
     FROM flood_map_exploded
     WHERE gid = :gid;
     ```

   - Logs a warning if some `gid` is not found in `flood_map_exploded`.

**Important property:** Because the table is truncated and rebuilt each run, any `gid` that no longer appears in `FloodedGeometry` also disappears from `current_flood_polygons`. There is no stale flood data in `gis_data`.

---

## 3. Risk-Aware Graph Routing (How PHP Uses current_flood_polygons)

### 3.1. SQL side (summarized)

In `gis_data` (documented fully in [README_GRAPH_ROUTING.md](README_GRAPH_ROUTING.md)) you have:

- `roads` (OSM roads with `geom`, `source`, `target`).
- `road_edges` view:

  ```sql
  CREATE OR REPLACE VIEW road_edges AS
  SELECT
    (gid::integer) AS id,
    (source::integer) AS source,
    (target::integer) AS target,
    geom,
    ST_Length(geom::geography) AS length_m
  FROM roads_noded;
  ```

  This view is rebuilt by the `roads:noded-build` command.

- `current_flood_polygons` (kept fresh by `SyncCurrentFloodPolygonsJob`).
- `road_edges_flooded` view that assigns a per-edge max risk level:

  ```sql
  CREATE OR REPLACE VIEW road_edges_flooded AS
  SELECT
    e.id,
    e.orig_gid,
    e.source,
    e.target,
    e.geom,
    e.length_m,
    MAX(fp.risk_level) AS edge_max_risk
  FROM road_edges e
  LEFT JOIN current_flood_polygons fp
    ON ST_Intersects(e.geom, fp.geom)
  GROUP BY
    e.id,
    e.orig_gid,
    e.source,
    e.target,

  If `current_flood_polygons` does not exist yet, the build command creates a safe fallback `road_edges_flooded` view where `edge_max_risk` is always `NULL`.
    e.geom,
    e.length_m;
  ```

- `compute_safe_route_geom` function (called from PHP) that:
  - Uses `road_edges_flooded` as input to `pgr_dijkstra`.
  - Filters edges by allowed risk based on `vehicle_type` / `routing_profile`.
  - Aggregates `max_risk_level` over the final path.
  - Optionally excludes motorway-class edges when `avoid_motorway=true`.

### 3.2. RoadRoutingService

File: [app/Services/RoadRoutingService.php](app/Services/RoadRoutingService.php)

Responsibilities:

1. On the `gis_data` connection, calls:
   - `snap_point_to_vertex(lat, lng, vehicle_type, routing_profile)` → start/end vertex IDs.
   - `compute_safe_route_geom(vehicle_type, routing_profile, start_vertex, end_vertex)` → route geometry and risk.
2. Decodes `path_geom_geojson` into a PHP array and returns a simple structure:

   - `geometry` – decoded GeoJSON (typically a `MultiLineString`).
   - `distance_m` – total length.
   - `max_risk_level` – highest flood risk on any edge in the path.

If any DB error occurs (e.g., no path, function missing), it logs and throws a `RuntimeException`, signaling to fall back to Mapbox.

### 3.3. SafeRoutingService

File: [app/Services/SafeRoutingService.php](app/Services/SafeRoutingService.php)

When `SAFE_ROUTING_ENGINE=graph`:

1. Calls `RoadRoutingService::computeSafeRoute()`.
2. On success, builds a response payload:

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

3. On failure (no path, DB error), logs a warning and falls back to the existing Mapbox-based logic.
4. When returning a Mapbox-based route, it annotates the response with:

   - `route['engine'] = 'mapbox'` so the client can distinguish sources.

### 3.4. SafeRouteController and Request

- [app/Http/Requests/SafeRouteRequest.php](app/Http/Requests/SafeRouteRequest.php) validates:
  - `origin.lat/lng`,
  - `destination.lat/lng`,
  - `routing_profile` (driving/traffic/walking/cycling),
  - `vehicle_type` (car/motor/truck),
  - optional `exclude` and `max_attempts`.

- [app/Http/Controllers/SafeRouteController.php](app/Http/Controllers/SafeRouteController.php) coordinates:

  1. Reads the validated inputs.
  2. Calls `SafeRoutingService::findSafeRoute()`.
  3. Wraps the result as JSON:

     ```php
     return response()->json([
         'status' => 'ok',
         'route'  => $route,
     ]);
     ```

---

## 4. Vehicle Rules and max_risk_level

The `compute_safe_route_geom` function enforces your rules inside the graph:

- `car`: allowed edges with `edge_max_risk <= 1`, avoids 2–3.
- `truck`: allowed edges with `edge_max_risk <= 2`, avoids 3.
- `motor` / `motorcycle` or `routing_profile` in `walking` / `cycling`: only edges with `edge_max_risk IS NULL` (treated as 0) are allowed; any flooded edge is excluded.

For reporting, `max_risk_level` in the returned route is:

- The maximum `edge_max_risk` along the chosen path (or 0 if no flooded edges were used).

This allows the frontend to know both **which engine** produced the route (`graph` vs `mapbox`) and **how bad** the worst flooded segment is along that route.

---

## 5. Summary

- `ComputeFloodedPolygonJob` builds per‑barangay flood FeatureCollections in `FloodedGeometry`.
- `SyncCurrentFloodPolygonsJob` turns those into a clean, always-fresh `current_flood_polygons` table on `gis_data`.
- `road_edges_flooded` and `compute_safe_route_geom` read from `current_flood_polygons` to make the pgRouting graph flood-aware.
- `RoadRoutingService` and `SafeRoutingService` expose this as `/api/route/safe`, with the response annotated by `engine` and `max_risk_level` so the mobile app can react accordingly.
