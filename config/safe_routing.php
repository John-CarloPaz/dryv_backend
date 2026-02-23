<?php

return [
    // Engine used by SafeRoutingService:
    // - 'mapbox' (default): current Mapbox Directions + heuristic detours.
    // - 'graph': use pgRouting / RoadRoutingService to compute the safest path first.
    'engine' => env('SAFE_ROUTING_ENGINE', 'mapbox'),

    // Douglas–Peucker simplification for graph engine outputs.
    // Increase tolerances to reduce geometry density; decrease to preserve lane shape.
    // Simplification only runs when the geometry has at least `*_min_points` points.
    'graph_simplify_tolerance_m' => (float) env('SAFE_ROUTING_GRAPH_SIMPLIFY_TOLERANCE_M', 2),
    'graph_simplify_min_points' => (int) env('SAFE_ROUTING_GRAPH_SIMPLIFY_MIN_POINTS', 250),
    'graph_step_simplify_tolerance_m' => (float) env('SAFE_ROUTING_GRAPH_STEP_SIMPLIFY_TOLERANCE_M', 1.5),
    'graph_step_simplify_min_points' => (int) env('SAFE_ROUTING_GRAPH_STEP_SIMPLIFY_MIN_POINTS', 25),

    // Optional visual map-matching: snaps returned graph geometry onto nearest road geometries.
    // This is purely for better rendering alignment; it does not affect the pgRouting path selection.
    'graph_map_match_enabled' => (bool) env('SAFE_ROUTING_GRAPH_MAP_MATCH_ENABLED', true),
    'graph_map_match_search_radius_m' => (float) env('SAFE_ROUTING_GRAPH_MAP_MATCH_SEARCH_RADIUS_M', 60),
    'graph_map_match_max_points' => (int) env('SAFE_ROUTING_GRAPH_MAP_MATCH_MAX_POINTS', 400),

    // Optional visual lane offset (meters) applied after map-match and before simplification.
    // Use this when your `roads` geometry is a centerline but the basemap shows split carriageways.
    // Positive values shift perpendicular to travel direction; side chooses right/left of travel.
    'graph_visual_offset_m' => (float) env('SAFE_ROUTING_GRAPH_VISUAL_OFFSET_M', 0),
    'graph_visual_offset_side' => (string) env('SAFE_ROUTING_GRAPH_VISUAL_OFFSET_SIDE', 'right'),
    // Safety: cap large offsets on small / two-way roads to avoid pushing geometry off the rendered street.
    // This is only for rendering; turn-by-turn steps are generated from un-offset geometry.
    'graph_visual_offset_minor_cap_m' => (float) env('SAFE_ROUTING_GRAPH_VISUAL_OFFSET_MINOR_CAP_M', 3.0),
    'graph_visual_offset_nearest_road_radius_m' => (float) env('SAFE_ROUTING_GRAPH_VISUAL_OFFSET_ROAD_RADIUS_M', 80),
    // For one-way road centerlines, any visual offset is usually unnecessary (and can push off-street).
    'graph_visual_offset_oneway_cap_m' => (float) env('SAFE_ROUTING_GRAPH_VISUAL_OFFSET_ONEWAY_CAP_M', 0.0),

    // Render-only cleanup: tiny DP simplification to remove micro-zigzags that appear as
    // unexpected "turns" on straight segments. Kept small to avoid cutting real corners.
    'graph_visual_simplify_tolerance_m' => (float) env('SAFE_ROUTING_GRAPH_VISUAL_SIMPLIFY_TOLERANCE_M', 0.6),

    // Cache graph routing responses (seconds). Helps dramatically for repeat requests.
    // Set to 0 to disable.
    'graph_cache_ttl_seconds' => (int) env('SAFE_ROUTING_GRAPH_CACHE_TTL_SECONDS', 300),
];
