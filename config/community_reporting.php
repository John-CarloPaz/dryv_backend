<?php

return [
    // Radius used to locate the nearest road segment to a reported point.
    'nearest_road_search_radius_m' => (float) env('COMMUNITY_REPORTING_NEAREST_ROAD_RADIUS_M', 80.0),

    // Segment bin size along a road (meters). Reports are grouped by (road_gid, segment_key).
    'segment_bin_size_m' => (float) env('COMMUNITY_REPORTING_SEGMENT_BIN_SIZE_M', 100.0),

    // Rolling window for aggregation (hours). Keeps CHI reflective of current conditions.
    'aggregation_window_hours' => (int) env('COMMUNITY_REPORTING_WINDOW_HOURS', 24),

    // CHI thresholds.
    // 0: 0-200
    // 1: 201-350
    // 2: 351-550
    // 3: >550
    'chi_thresholds' => [
        'low' => (float) env('COMMUNITY_REPORTING_CHI_LOW', 201.0),
        'medium' => (float) env('COMMUNITY_REPORTING_CHI_MEDIUM', 351.0),
        'high' => (float) env('COMMUNITY_REPORTING_CHI_HIGH', 551.0),
    ],
];
