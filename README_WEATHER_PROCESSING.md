# Weather Processing Calculation Guide

This document explains the calculations performed in
[app/Services/WeatherProcessingService.php](app/Services/WeatherProcessingService.php), specifically the `process()` method that produces a `Weather` record per barangay.

---

## 1. Inputs and Stored State

**Inputs**

- Barangay geometry/metadata: `Barangay $barangay`
- Hourly forecast (OpenWeather style) in `$forecastData['list']`:
  - `rain.1h` (mm)
  - `pop` (0–1 probability of precipitation)
  - `main.temp_min` and `main.temp_max` (°C)
- Optional satellite radiation data `$satelliteData`:
  - Either `['hourly']['global_tilted_irradiance']` or `['global_tilted_irradiance']`
    (array of W/m² for each hour)

**Previously stored state (from last run)**

- Previous soil index $SI_{prev}$:
  - `Weather::where('barangay_id', $id)->value('si_score') ?? 0.0`
- Previous runoff $R_{prev}$ (mm):
  - `Weather::where('barangay_id', $id)->value('runoff') ?? 0.0`

**Fixed parameters**

- Field capacity (max soil water) $FC = 45$ mm
- Soil absorption fraction $f_{abs} = 0.15$

Initial soil moisture at start of this run:

$$
SM_0 = SI_{prev} \times FC
$$

---

## 2. Aggregating Forecast: Rain, POP, Temperatures

Loop over each hourly entry $h \in list$:

- Hourly rain:
  - $rain_h = rain.1h$ (mm, default 0)
- Probability of precipitation:
  - $pop_h = pop$ (0–1, default 0)

Accumulators:

- Total forecast rainfall over the horizon:
  $$
  R_{acc} = \sum_h rain_h
  $$
- Average POP (converted to %):
  $$
  POP_{avg} =
  \begin{cases}
  \frac{1}{N} \sum_h pop_h \times 100 & \text{if } N > 0 \\
  0 & \text{if } N = 0
  \end{cases}
  $$
- Track min and max temperature across all hours:
  - $T_{min} = \min_h(temp\_min_h)$
  - $T_{max} = \max_h(temp\_max_h)$
- Average temperature:
  $$
  T_{avg} =
  \begin{cases}
  \frac{T_{min} + T_{max}}{2} & \text{if both known} \\
  \text{null} & \text{otherwise}
  \end{cases}
  $$

---

## 3. Satellite Global Tilted Irradiance (GHI)

From `$satelliteData`:

- Take array `hours` as either:
  - `$satelliteData['hourly']['global_tilted_irradiance']` or
  - `$satelliteData['global_tilted_irradiance']`
- Each element is assumed to be an hourly average power in W/m².

Convert to daily energy in MJ/m²:

1 W/m² for 1 hour ≈ 0.0036 MJ/m².

$$
GHI_{day} = \sum_{hours} ghi_h \times 0.0036 \quad [\text{MJ m}^{-2} \text{ day}^{-1}]
$$

Stored as `solar_irradiance`.

---

## 4. Hargreaves Evapotranspiration (ET)

Only computed if:

- $T_{min}$, $T_{max}$, and $T_{avg}$ are numeric, and
- $GHI_{day} > 0$

Convert daily GHI to an approximate hourly radiation:

$$
R_a^{hour} = \frac{GHI_{day}}{24} \quad [\text{MJ m}^{-2} \text{ hr}^{-1}]
$$

Define:

- $T_{mean} = \frac{T_{min} + T_{max}}{2}$
- $T_{range} = \max(T_{max} - T_{min}, 0.1)$ (avoid zero)

Hourly Hargreaves ET:

$$
ET_{hour} = 0.0023 \times (T_{mean} + 17.8) \times \sqrt{T_{range}} \times R_a^{hour}
$$

Daily ET:

$$
ET_{day} = ET_{hour} \times 24
$$

Stored as:

- `hargreaves_hourly = ET_hour`
- `hargreaves_index = ET_day`

---

## 5. Soil Moisture Update Over Forecast Horizon

Initialize:

- $SM = SM_0$ (mm)

For each forecast hour:

1. Hourly rain $rain_h$ (mm)
2. Rain infiltrated into soil depends on current saturation:

   $$
   R_{abs,h} = rain_h \times f_{abs} \times \left(1 - \frac{SM}{FC}\right)
   $$

   Clamp:

   $$
   R_{abs,h} = \max(0, R_{abs,h})
   $$

3. ET reduces soil moisture using the same hourly ET for all hours:

   $$
   SM = SM + R_{abs,h} - ET_{hour}
   $$

4. Clamp soil moisture between 0 and field capacity:

   $$
   SM = \min(FC, \max(0, SM))
   $$

After the loop, define current Soil Index:

$$
SI_{current} = \frac{SM}{FC} \in [0, 1]
$$

Stored as `si_score`. Final `soil_moisture` is $SM$ in mm.

---

## 6. Runoff Calculation

Let:

- $R_{acc}$ = total forecast rain from step 2 (mm)
- $SI_{prev}$ = previous SI from last stored `Weather`
- $R_{prev}$ = previous stored runoff (mm)
- $SI_{current}$ = current SI after soil update

1. Estimate rain infiltrated due to soil capacity (based on previous SI):

   $$
   R_{infil,forecast} = R_{acc} \times f_{abs} \times (1 - SI_{prev})
   $$

2. Remaining soil capacity after the new state:

   $$
   A_{avail} = (1 - SI_{current}) \times FC
   $$

3. Let some previous runoff infiltrate into remaining capacity:

   $$
   R_{infil,prev} = \min\left(R_{prev}, f_{abs} \times A_{avail}\right)
   $$

4. Adjust previous runoff:

   $$
   R_{prev,adj} = \max(0, R_{prev} - R_{infil,prev})
   $$

5. New runoff from current forecast rain:

   $$
   R_{new} = \max\bigl(0, R_{acc} - R_{infil,forecast}\bigr)
   $$

6. Final runoff carried forward:

$$
R_{runoff} = R_{prev,adj} + R_{new}
$$

Stored as `runoff`.

---

## 7. Persistence and Side Effects

The service updates or creates a `Weather` row with:

- `barangay_id`
- `data` → full forecast payload
- `si_score` → $SI_{current}$
- `fetched_at` → `now()`
- `accumulated_rainfall` → $R_{acc}$
- `ave_pop_percentage` → $POP_{avg}$
- `temp_min`, `temp_max`, `temp_avg`
- `solar_irradiance` → $GHI_{day}$
- `hargreaves_index` → $ET_{day}$
- `hargreaves_hourly` → $ET_{hour}$
- `soil_moisture` → final $SM$
- `runoff` → $R_{runoff}$

After saving, it dispatches:

- `ComputeFloodRiskJob::dispatch($weather);`

which uses the updated SI, runoff, and rainfall to compute downstream flood risk and flood polygons for that barangay.
