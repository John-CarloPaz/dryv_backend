# Dryv Backend — Repo Overview

This document summarizes the repository structure, main components, how they relate, and the runtime/data flow the system currently implements.

## Purpose

Dryv Backend provides flood-aware data and APIs used by the Dryv mobile app. It:
- fetches weather forecasts,
- computes rainfall accumulation,
- calculates flood risk by intersecting flood polygons (Project NOAH) with administrative boundaries,
- stores flood flags and polygon geometry,
- publishes aggregated flooded geometries (used for visualization or Mapbox uploads).

## High-level flow

1. `app/Jobs/FetchWeatherJob.php` fetches hourly forecast for a `Barangay` from OpenWeather.
2. `ComputeTotalRainfallJob` computes accumulated rainfall (3-hour window) and updates the `weathers` record.
3. `ComputeFloodRiskJob` uses the accumulated rainfall and Project NOAH polygons to compute an RWR-based risk and writes a `floodeds` entry.
4. `ComputeFloodedPolygonJob` (dispatched from `ComputeFloodRiskJob`) builds flooded polygons per barangay and stores them in `flooded_geometries` (model: `FloodedGeometry`).
5. `UploadGeoJsonToMapbox` can aggregate `FloodedGeometry` entries into a single GeoJSON and push to a Mapbox Dataset (configured in `config/services.php`).

## Key Components and Files

- Routes
  - [routes/web.php](routes/web.php): basic web route (welcome page).
  - [routes/api.php](routes/api.php): primary API surface for barangay data and flood queries.

- Controllers
  - [app/Http/Controllers/BarangayController.php](app/Http/Controllers/BarangayController.php): CRUD and retrieval endpoints for barangays; delegates to services.
  - [app/Http/Controllers/DisasterReportsController.php](app/Http/Controllers/DisasterReportsController.php): aggregates flooded barangays for reports.

- Services
  - [app/Services/BarangayRetrievalService.php](app/Services/BarangayRetrievalService.php): read-only retrieval helpers.
  - [app/Services/BarangayCreationService.php](app/Services/BarangayCreationService.php): create/update/delete barangays and bulk import.

- Jobs (background processing)
  - [app/Jobs/FetchWeatherJob.php](app/Jobs/FetchWeatherJob.php): fetches weather and chains flood computation jobs.
  - [app/Jobs/ComputeTotalRainfallJob.php](app/Jobs/ComputeTotalRainfallJob.php): calculates rainfall accumulation and dispatches risk computation.
  - [app/Jobs/ComputeFloodRiskJob.php](app/Jobs/ComputeFloodRiskJob.php): finds intersecting NOAH flood polygons and writes `floodeds` record; dispatches geometry computation.
  - [app/Jobs/ComputeFloodedPolygonJob.php](app/Jobs/ComputeFloodedPolygonJob.php): builds polygon geometry per barangay and stores in `flooded_geometries`.
  - [app/Jobs/UploadGeoJsonToMapbox.php](app/Jobs/UploadGeoJsonToMapbox.php): aggregates `flooded_geometries` and pushes GeoJSON to Mapbox Dataset API.

- Models (DB tables)
  - [app/Models/Barangay.php](app/Models/Barangay.php) — `barangays` table, geolocation and relations.
  - [app/Models/Weather.php](app/Models/Weather.php) — `weathers`, stores API `data` and accumulated rainfall.
  - [app/Models/Flooded.php](app/Models/Flooded.php) — `floodeds`, per-barangay risk summary and reported time.
  - [app/Models/FloodedGeometry.php](app/Models/FloodedGeometry.php) — stores GeoJSON fragments per barangay.
  - [app/Models/Boundary.php](app/Models/Boundary.php) — wraps PostGIS `pampanga_boundary` table used to match barangays to GIDs.
  - [app/Models/Noah.php](app/Models/Noah.php) — `flood_map_exploded` (NOAH polygons used for intersections).

- Config & secrets
  - [config/services.php](config/services.php): contains `openweather` and `mapbox` configuration keys pulled from environment variables.

- Database seeds / data
  - [database/seeders/BarangaySeeder.php](database/seeders/BarangaySeeder.php) reads `database/data/barangays.json` and upserts into `barangays`.

## Dataflow details (step-by-step)

- Weather fetch
  - `FetchWeatherJob` requests hourly forecast using coordinates from `barangays`.
  - On success, it `updateOrCreate`s a `Weather` row with `data` and `fetched_at`.
  - It then chains `ComputeTotalRainfallJob`, `ComputeFloodRiskJob`, and `ComputeFloodedPolygonJob`.

- Rainfall accumulation
  - `ComputeTotalRainfallJob` samples up to 3 forecast hours and sums rainfall where probability-of-precip >= 0.6.
  - The `accumulated_rainfall` field on `weathers` is updated.

- Risk computation
  - `ComputeFloodRiskJob` finds the barangay’s boundary GID by matching text fields in `pampanga_boundary` (`Boundary` model).
  - It fetches NOAH polygons that intersect that boundary via raw PostGIS queries.
  - For each intersecting polygon it computes RWR = `var * accumulated_rainfall` and maps thresholds to risk levels.
  - If flood(s) detected, a `floodeds` record is updated and `ComputeFloodedPolygonJob` is dispatched.

- Geometry aggregation and publishing
  - `ComputeFloodedPolygonJob` should be responsible for producing GeoJSON polygons for each barangay and storing them in `flooded_geometries`.
  - `UploadGeoJsonToMapbox` aggregates all `flooded_geometries` and PUTs them to Mapbox Dataset API using credentials in `config/services.php`.

## Where to look for important logic

- Matching barangay → PostGIS boundary GID: [app/Jobs/ComputeFloodRiskJob.php](app/Jobs/ComputeFloodRiskJob.php)
- NOAH polygons table and PostGIS queries: [app/Models/Noah.php](app/Models/Noah.php) and raw queries in jobs.
- Mapbox upload: [app/Jobs/UploadGeoJsonToMapbox.php](app/Jobs/UploadGeoJsonToMapbox.php)
- Barangay creation & import: [app/Services/BarangayCreationService.php](app/Services/BarangayCreationService.php) and [database/seeders/BarangaySeeder.php](database/seeders/BarangaySeeder.php)

## Running & testing locally (quick)

1. Copy environment and set keys:

```bash
cp .env.example .env
composer install
php artisan key:generate
```

2. Configure `.env` (Postgres + PostGIS and API keys):

- `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `OPEN_WEATHER_API_KEY`, `MAPBOX_ACCESS_TOKEN`, `MAPBOX_USERNAME`, `MAPBOX_DATASET_ID`

3. Migrate & seed:

```bash
php artisan migrate
php artisan db:seed
```

4. Run queue workers (jobs drive the flood detection flow):

```bash
php artisan queue:work --tries=3
```

5. Trigger a single barangay weather fetch for testing (example snippet; adapt to your tinker or route):

```php
$b = App\\Models\\Barangay::first();
App\\Jobs\\FetchWeatherJob::dispatch($b);
```

## Notes, assumptions, and things to check

- The system expects a PostGIS-enabled Postgres database and NOAH flood polygons in `flood_map_exploded`.
- The `Boundary` model uses `pampanga_boundary` and relies on text matching in `ComputeFloodRiskJob` — this can be brittle and may need a better matching strategy (e.g., normalized codes or spatial joins).
- `UploadGeoJsonToMapbox` expects valid `MAPBOX_*` env vars and will PUT a dataset to Mapbox; ensure quotas and dataset permissions.
- Error handling is mostly logged; consider surfaced alerts for failed jobs.

## Suggested next maintenance steps

1. Add unit/integration tests around `ComputeTotalRainfallJob` and `ComputeFloodRiskJob` to validate thresholds.
2. Replace text-based boundary matching with a spatial lookup or canonical IDs.
3. Add a CLI command to run one-off dataset uploads (wrap `UploadGeoJsonToMapbox`).
4. Add documentation for database table origins (where NOAH shapefiles came from and how to re-import).

---

If you want, I can now:
- commit this overview and update `README.md` with a short pointer to it,
- run a quick pass to add a small CLI command to export a sample GeoJSON for local debugging,
- or run the test seed/migration commands locally (requires your environment). 

-- Repo assistant
