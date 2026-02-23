<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunityFloodRoadStat extends Model
{
    protected $table = 'community_flood_road_stats';

    protected $fillable = [
        'road_gid',
        'segment_key',
        'center_lat',
        'center_lng',
        'chi_score',
        'risk_level',
        'reports_count',
        'avg_estimated_depth',
        'last_reported_at',
    ];

    protected $casts = [
        'road_gid' => 'int',
        'segment_key' => 'int',
        'center_lat' => 'float',
        'center_lng' => 'float',
        'chi_score' => 'float',
        'risk_level' => 'int',
        'reports_count' => 'int',
        'avg_estimated_depth' => 'float',
        'last_reported_at' => 'datetime',
    ];
}
