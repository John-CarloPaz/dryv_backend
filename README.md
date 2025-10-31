# 🚗 Dryv

**Smart Flood-Aware Navigation App**

Dryv is a Flutter-based mobile application designed to provide safe and efficient navigation during flood events. It integrates multiple data sources, including Project NOAH, Open-Meteo, and OpenWeather, combined with Mapbox’s routing and visualization capabilities. By analyzing real-time and historical flood data, Dryv intelligently detects flooded roads and dynamically reroutes users through the safest available paths.

---

## 🌊 Key Features

* **Real-Time Flood Detection:**
  Integrates with **Project NOAH’s flood polygon database** using **PostgreSQL + PostGIS** to detect areas at risk.

* **Rainfall-Based Flood Forecasting:**
  Fetches accumulated rainfall data from **Open-Meteo** and **OpenWeather APIs** to estimate potential flood zones.

* **Dynamic Routing:**
  Uses **Mapbox Directions API** for navigation and reroutes users automatically when a flooded road is detected.

* **Interactive Flood Map:**
  Displays a live, zoomable map with **flooded regions**, **routes**, and **user position** using **Mapbox Maps Flutter**.

* **Offline Support (planned):**
  Store last known flood zones and base maps for navigation during connectivity loss.

---

## 🗺️ System Architecture

```
+-------------------------+
|      Flutter App        |
| (UI, Routing, Mapbox)   |
+-----------+-------------+
            |
            | REST API calls
            v
+-------------------------+
|     Backend Server      |
| (Laravel / Node / etc.) |
+-----------+-------------+
            |
            | Spatial queries (PostGIS)
            v
+-------------------------+
| PostgreSQL + PostGIS DB |
| Flood polygons (NOAH)   |
| Road network (OSM)      |
+-----------+-------------+
            |
            | Weather APIs
            v
+-------------------------+
| OpenMeteo / OpenWeather |
+-------------------------+
```

---

## 🧩 Tech Stack

| Layer              | Technology                                 |
| ------------------ | ------------------------------------------ |
| Frontend           | Flutter (Dart)                             |
| Maps & Navigation  | Mapbox Maps Flutter, Mapbox Directions API |
| Database           | PostgreSQL + PostGIS                       |
| Data Sources       | Project NOAH, Open-Meteo, OpenWeather      |
| Hosting (optional) | AWS / Railway / Supabase                   |
| APIs               | Custom backend or direct API calls         |

---

## ⚙️ Setup Instructions

### 1. Prerequisites

* Flutter SDK (>=3.0)
* Dart (>=3.0)
* PostgreSQL (>=14) with PostGIS extension
* Mapbox access token
* OpenWeather and Open-Meteo API keys

### 2. Clone the Repository

```bash
git clone https://github.com/yourusername/dryv.git
cd dryv
```

### 3. Install Dependencies

```bash
flutter pub get
```

### 4. Set Environment Variables

Create a `.env` file (or Flutter environment config):

```
MAPBOX_ACCESS_TOKEN=your_mapbox_token
OPENWEATHER_API_KEY=your_openweather_key
OPENMETEO_API_URL=https://api.open-meteo.com/v1/forecast
DATABASE_URL=postgres://user:password@localhost:5432/dryv
```

### 5. Run PostgreSQL + PostGIS

```sql
CREATE DATABASE dryv;
\c dryv
CREATE EXTENSION postgis;
```

Import Project NOAH flood polygons into your database.

### 6. Run the App

```bash
flutter run
```

---

## 🧠 How It Works

1. **Weather Fetching:**
   The app retrieves rainfall intensity and accumulation data via Open-Meteo and OpenWeather APIs.

2. **Flood Risk Calculation:**
   The backend evaluates rainfall data and cross-references flood polygons from Project NOAH using PostGIS spatial queries (`ST_Intersects`, `ST_Within`, etc.).

3. **Routing:**
   When a user requests navigation, the app uses Mapbox Directions API but filters out flooded roads. If a route crosses a flooded polygon, Dryv requests an alternate route.

4. **Map Visualization:**
   Mapbox renders:

   * Blue polygons = flood zones
   * Red lines = blocked roads
   * Green lines = safe routes

---

## 🧪 Future Plans

* Integration with **government flood sensors** (DPWH, PAGASA)
* Push notifications for **flood warnings**
* Offline cached maps and **offline rerouting**
* Community reporting (user-submitted flood data)
* Emergency contact quick-access during navigation

---

## 📸 Screenshots (Coming Soon)

| Map View | Flood Zones | Safe Route |
| -------- | ----------- | ---------- |
| 🗺️      | 🌊          | ✅          |

---

## 📜 License

This project is licensed under the MIT License.

---

## 👨‍💻 Author

**Zap John Carlo**
📍 Pampanga, Philippines
💡 Passionate about mobile development, spatial data, and real-world AI integration.
