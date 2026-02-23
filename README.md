# 🌊 Dryv Backend

**Flood-Aware Routing and Geospatial API**

The Dryv backend powers the Dryv mobile app by providing real-time flood detection, weather-based flood prediction, and safe route generation.
It integrates **PostgreSQL + PostGIS** for spatial analysis, **Project NOAH** flood polygons for hazard data, **Open-Meteo** and **OpenWeather** for rainfall data, and **Mapbox** for map rendering and routing.

---

## 🧭 Overview

Dryv Backend exposes a REST API that:

1. Collects rainfall and weather data.
2. Calculates flood probability based on rainfall accumulation and flood polygons.
3. Updates a flood risk table in PostgreSQL.
4. Intercepts route requests and reroutes through safe paths using a pgRouting-based road graph with Mapbox Directions as a fallback.

---

## 🏗️ System Architecture

```
[ Open-Meteo / OpenWeather ] ---> [ Dryv Backend ] ---> [ PostgreSQL + PostGIS ]
                                         |
                                         | (Flood Risk & Routing API)
                                         v
                                     [ Mobile App ]
```

---

## ⚙️ Technology Stack

| Component         | Technology                               |
| ----------------- | ---------------------------------------- |
| Language          | PHP 8 / Laravel 12                       |
| Database          | PostgreSQL 15 + PostGIS                  |
| Map & Routing     | pgRouting + Mapbox Directions API        |
| Flood Data Source | Project NOAH Database                    |
| Weather APIs      | Open-Meteo, OpenWeather                  |
| Queue / Jobs      | Laravel Queues (Redis / Database driver) |
| Deployment        | Docker / Railway / VPS                   |

---

## 🧩 Features

* Fetches **real-time rainfall** from Open-Meteo & OpenWeather.
* Uses **PostGIS spatial queries** to detect flood intersections with road networks.
* Stores **flood polygons** (Project NOAH shapefiles) for historical and real-time overlay.
* Computes **flood risk score per road segment**.
* Flags flooded segments for rerouting.
* Provides REST endpoints for mobile app integration.

---

## 🗄️ Database Schema (Simplified)

| Table          | Purpose                                                        |
| -------------- | -------------------------------------------------------------- |
| `noah_floods`  | Stores flood polygons imported from Project NOAH               |
| `roads`        | Contains road geometries (from OpenStreetMap or local dataset) |
| `flooded`      | Stores currently flooded segments                              |
| `weather_data` | Caches rainfall accumulation from APIs                         |
| `boundaries`   | Administrative boundary geometries (barangay, city, province)  |


---

## 🌧️ Flood Risk Logic

1. Fetch rainfall data (mm/hour) from **Open-Meteo** and **OpenWeather**.
2. Compute **rainfall accumulation** for each boundary (barangay/city).
3. Determine if rainfall threshold > flood risk baseline (e.g., 50 mm/3 hrs).
4. Use PostGIS to intersect flood polygons and roads:

   ```sql
   SELECT r.id, r.name
   FROM roads r
   JOIN noah_floods f
     ON ST_Intersects(r.geom, f.geom);
   ```
5. Mark intersecting roads as flooded and store them in the `flooded` table.
6. Return JSON response to client (for rerouting or visualization).

---

## 🔗 API Endpoints

| Method | Endpoint                        | Description                                       |
| ------ | ------------------------------- | ------------------------------------------------- |
| `GET`  | `/api/weather/sync`             | Fetch rainfall data from Open-Meteo & OpenWeather |
| `POST` | `/api/floods/compute`           | Compute flood risk & update flooded table         |
| `GET`  | `/api/floods/in-boundary/{gid}` | Return flooded roads within a barangay            |
| `POST` | `/api/route/safe`               | Graph-based safe routing (with Mapbox fallback)   |
| `GET`  | `/api/floods`                   | Get all current flood polygons                    |
| `GET`  | `/api/status`                   | Health check for system uptime                    |

---

## 🧮 Example Flood Computation Job (Laravel)

```php
class ComputeFloodRiskJob implements ShouldQueue
{
    use Queueable;

    private $weather;

    public function __construct($weather)
    {
        $this->weather = $weather;
    }

    public function handle(): void
    {
        $rainfall = $this->weather['accumulated'];

        // Step 1: Determine rainfall threshold
        if ($rainfall > 50) {
            // Step 2: Find roads intersecting flood polygons
            $flooded = DB::select("
                SELECT r.id, r.name
                FROM roads r
                JOIN noah_floods f
                ON ST_Intersects(r.geom, f.geom)
            ");

            // Step 3: Store flagged roads
            foreach ($flooded as $road) {
                Flooded::updateOrCreate(['road_id' => $road->id]);
            }
        }
    }
}
```

---

## 🧰 Setup Instructions

### 1. Clone Repository

```bash
git clone https://github.com/yourusername/dryv-backend.git
cd dryv-backend
```

### 2. Install Dependencies

```bash
composer install
cp .env.example .env
php artisan key:generate
```

### 3. Configure Environment

Edit `.env`:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=dryv
DB_USERNAME=postgres
DB_PASSWORD=your_password

MAPBOX_TOKEN=your_mapbox_access_token
OPENWEATHER_KEY=your_openweather_key
OPENMETEO_API=https://api.open-meteo.com/v1/forecast
```

### 4. Initialize Database

```bash
php artisan migrate
psql -d dryv -c "CREATE EXTENSION postgis;"
```

### 5. Import Project NOAH Flood Data

Import shapefiles:

```bash
shp2pgsql -I -s 4326 noah_floods.shp public.noah_floods | psql -d dryv
```

### 6. Run Scheduler / Queue

```bash
php artisan schedule:work
php artisan queue:work
```

---

## 🧠 Future Enhancements

* Integrate **real-time water level sensors** (DPWH, PAGASA)
* Add **historical rainfall trend analysis**
* Provide **web dashboard** for admins to view flood spread
* Implement **machine learning flood prediction** model
* Cache Mapbox routes for faster rerouting

---

## 📜 License

MIT License © 2025 Zap John Carlo

---

## 💬 Author

**Zap John Carlo**
🌍 Pampanga, Philippines
💡 Focused on backend, GIS, and disaster-resilient systems.
