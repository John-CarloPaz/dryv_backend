# Safe Flood-Aware Routing API

This document describes the flood-aware routing endpoint that integrates:

- A **graph-based routing engine** built on PostGIS + pgRouting.
- A **Mapbox routing engine** using the Mapbox Directions API.
- The existing flood geometry pipeline (Project NOAH polygons and FloodedGeometry).

## Endpoint

- Method: POST
- URL: `/api/route/safe`
- Controller: `App\Http\Controllers\SafeRouteController@route`

### Request Body (JSON)

```json
{
  "origin": { "lat": 15.166754, "lng": 120.580551 },
  "destination": { "lat": 15.154253, "lng": 120.592161 },
  "routing_profile": "driving",
  "vehicle_type": "car",          // car | motor | truck
  "avoid_motorway": true,
  "toggle_community_report": true,
  "exclude": ["toll", "motorway"],
  "max_attempts": 5
}
```

Notes:

- `avoid_motorway` / `exclude: ["motorway"]`
  - Mapbox engine: supported.
  - Graph engine: supported **after installing the `gis_data` SQL overloads** (see `README_GRAPH_ROUTING.md`).
    If the overloads are missing, the backend returns “Motorway avoidance is not supported by the current graph routing database functions.”

- `toggle_community_report` (default: `false`)
  - When `true` and the graph engine is used, the route will also avoid community-reported flood segments, using the same vehicle risk rules as flooded polygons (car/motor avoid risk 2–3; truck avoid 3; walking avoids any risk).

### Response (Success)

Graph engine (pgRouting over roads graph):

```json
{
  "status": "ok",
  "route": {
    "engine": "graph",
    "routes": [
      {
        "geometry": { "type": "MultiLineString", "coordinates": [/* ... */] },
        "distance": 1744.65,
        "weight": 1744.65,
        "duration": null,
        "max_risk_level": 1
      }
    ]
  }
}
```

Mapbox fallback:

```json
{
  "status": "ok",
  "route": {
    "engine": "mapbox",
    "routes": [
      {
        "geometry": { "type": "LineString", "coordinates": [/* ... */] },
        "distance": 2000.12,
        "duration": 320.5,
        "legs": [/* raw Mapbox legs */]
      }
    ]
  }
}
```

### Response (Error)

- `422 Unprocessable Entity` when no safe route can be found within the configured attempts.
- `500 Internal Server Error` for unexpected errors.

## Routing Engines

The active routing engine is controlled by `SAFE_ROUTING_ENGINE` in `.env` (see `config/safe_routing.php`):

- `SAFE_ROUTING_ENGINE=graph` → use pgRouting/graph engine (no implicit fallback).
- `SAFE_ROUTING_ENGINE=mapbox` → use Mapbox.

In both cases, the response includes an `engine` field (`graph` or `mapbox`) inside `route` so the client can see which backend was used.

## Graph-Based Routing (pgRouting)

The graph engine runs entirely inside the `gis_data` PostgreSQL database:

1. **Road graph**

   - Source: `roads` table (imported from OSM).
   - Geometry SRID: 4326 (`geom` column).

2. **Topology creation (recommended: roads_noded)**

  If your roads came from an OSM shapefile export, you typically must node/split at intersections first. We route using `roads_noded` + `roads_noded_vertices_pgr` (built by pgRouting) and expose a stable `road_edges` view with integer ids.

  Easiest reproducible setup:

  ```bash
  php artisan roads:noded-build --connection=gis_data --tile=0.05 --pad=0.01 --topology-tolerance=0.00001 --mode=node
  ```

  Why `--mode=node`:

  - Some roads cross in 2D but are not connected in reality (bridges/underpasses).
  - `--mode=node` nodes/splits roads at intersections **only within the same grade group** (uses `roads.level` if present; otherwise falls back to `roads.bridge`).
  - This prevents routes from “jumping” between an underpass road and an overpass motorway just because geometries intersect.

  Full SQL (including the tile-based noding DO block, view casts, and snap function) is documented in `README_GRAPH_ROUTING.md`.

  If you’re running the SQL manually, the key points are:

  - `pgr_createTopology('roads_noded', 0.00001, 'geom', 'gid', 'source', 'target')`
  - `road_edges` view casts `gid/source/target` to integer
  - `snap_point_to_vertex` selects from `roads_noded_vertices_pgr`

  SRID 4326 tolerances are in **degrees**, not meters.

  Legacy (not recommended for OSM shapefile exports): building topology directly on `roads` often produces disconnected components.

  Example (legacy):

   ```sql
   -- 1) Ensure PostGIS + pgRouting are enabled
   CREATE EXTENSION IF NOT EXISTS postgis;
   CREATE EXTENSION IF NOT EXISTS pgrouting;

   -- 2) Add topology columns if they do not exist
   ALTER TABLE roads
     ADD COLUMN IF NOT EXISTS source bigint,
     ADD COLUMN IF NOT EXISTS target bigint;

   -- 3) (Re)build topology with ~100m tolerance (SRID 4326)
   UPDATE roads SET source = NULL, target = NULL;
   DROP TABLE IF EXISTS roads_vertices_pgr CASCADE;

   SELECT pgr_createTopology('roads', 0.001, 'geom', 'gid', 'source', 'target');

   -- 4) Helpful indexes
   CREATE INDEX IF NOT EXISTS roads_geom_gist
     ON roads
     USING GIST (geom);

   CREATE INDEX IF NOT EXISTS roads_vertices_pgr_geom_gist
     ON roads_vertices_pgr
     USING GIST (the_geom);

   VACUUM ANALYZE roads;
   VACUUM ANALYZE roads_vertices_pgr;
   ```

3. **Edges view + snapping**

  See `README_GRAPH_ROUTING.md` for the exact `road_edges` view definition (with casts) and the `snap_point_to_vertex` definition that uses `roads_noded_vertices_pgr`.

5. **Shortest path function**

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
   BEGIN
     RETURN QUERY
     WITH
       path AS (
         SELECT * FROM pgr_dijkstra(
           'SELECT id, source, target,
                   ST_Length(geom::geography) AS cost,
                   ST_Length(geom::geography) AS reverse_cost
            FROM road_edges',
           in_start_vertex,
           in_end_vertex,
           directed := false
         )
       ),
       edges AS (
         SELECT
           p.seq,
           e.geom,
           e.length_m
         FROM path p
         JOIN road_edges e ON e.id = p.edge
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
       0 AS max_risk_level; -- placeholder for flood-aware risk aggregation
   END;
   $$;
   ```

Later, this function can be extended to join flood polygons per edge and compute a true `max_risk_level` based on vehicle rules.

## Mapbox Fallback Routing

When `SAFE_ROUTING_ENGINE=graph`, the API uses the pgRouting/graph engine.

If the graph has no path between snapped vertices (different connected components) or a DB error occurs, it returns an error response. To use Mapbox instead, set `SAFE_ROUTING_ENGINE=mapbox`.

If your client requires `avoid_motorway=true`, either:

- Use `SAFE_ROUTING_ENGINE=mapbox`, or
- Extend the SQL graph functions (`compute_safe_route_geom`) to accept an extra parameter and filter edges by road type.

The Mapbox call still supports `exclude` and `max_attempts` and can perform iterative detours around flooded polygons as a backup.

## LineString–Polygon Intersection Example (PHP Library)

The production code uses PostGIS for intersection checks, but the same logic could be implemented purely in PHP by using a geometry library like `geoPHP` or `brick/geo`:

```php
use GeoJson\Geometry\LineString;
use GeoJson\Geometry\Polygon;
use Brick\Geo\IO\GeoJSONReader;

$reader = new GeoJSONReader();

/** @var Brick\Geo\LineString $line */
$line = $reader->read($lineStringGeoJson);

/** @var Brick\Geo\Polygon $polygon */
$polygon = $reader->read($polygonGeoJson);

if ($line->relate($polygon)->intersects()) {
    // The route LineString intersects the flooded Polygon.
}
```

This example is for reference only; the actual implementation in this project delegates intersection checks to PostGIS for performance and accuracy.
